<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Sales\Actions\ActivateRoundingPolicy;
use App\Modules\Sales\Actions\CreateRoundingPolicyDraft;
use App\Modules\Sales\Models\RoundingPolicyVersion;
use App\Modules\Sales\Support\Authority\DutyClass;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Financial\Arithmetic;
use App\Modules\Sales\Support\Financial\Policy\DraftRevalidation;
use App\Modules\Sales\Support\Financial\Policy\ResolveRoundingPolicy;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicy;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicyBinding;
use App\Modules\Sales\Support\Financial\Policy\SettlementRounding;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\CompanyContext;
use App\Support\PermissionRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FR4/PX6 — the versioned Sales rounding and unit-price precision policy over the real database: guarded draft and activation, half-open effective
 * periods, immutable history, company isolation, and a duty that stays disabled until it is deliberately activated (ADR 0120, 0144, 0138).
 */
class RoundingPolicyVersionsTest extends TestCase
{
    use RefreshDatabase;

    private const DUTY = PermissionVocabulary::FINANCIAL_CONFIGURE;

    private User $configurer;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate(self::DUTY, 'web');
        $this->configurer = User::factory()->create();
        $this->configurer->givePermissionTo(self::DUTY);
    }

    /** The shipped manifest with the staged duty switched on, to test the Actions that the shipped manifest keeps disabled. */
    private function enableDuty(): void
    {
        $duties = [];
        foreach (SalesDutyManifest::shipped()->all() as $duty) {
            $duties[] = $duty->name === self::DUTY ? new SalesDuty($duty->name, DutyClass::Sensitive, DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty;
        }
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));
    }

    private function today(): string
    {
        return now(Company::findOrFail(1)->timezone)->format('Y-m-d');
    }

    private function day(int $offset): string
    {
        return now(Company::findOrFail(1)->timezone)->addDays($offset)->format('Y-m-d');
    }

    private function draft(int $scale = 4, SettlementRounding $rounding = SettlementRounding::HalfUp, string $reason = 'Quote in whole cents', ?User $by = null): RoundingPolicyVersion
    {
        return app(CreateRoundingPolicyDraft::class)($by ?? $this->configurer, $scale, $rounding, $reason);
    }

    private function activate(RoundingPolicyVersion $policy, string $from, ?User $by = null): RoundingPolicyVersion
    {
        return app(ActivateRoundingPolicy::class)($policy, $by ?? $this->configurer, $from);
    }

    private function resolve(string $date): RoundingPolicy
    {
        return app(ResolveRoundingPolicy::class)($date);
    }

    /** The database refuses the statement. A savepoint keeps the surrounding test transaction usable after Postgres aborts the failed one. */
    private function assertDbRefuses(\Closure $call): void
    {
        try {
            DB::transaction($call);
            $this->fail('Expected the database to refuse.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $refusal) {
            return (string) $refusal->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    public function test_the_duty_is_staged_so_the_actions_refuse_everyone_including_a_raw_grant(): void
    {
        $this->assertTrue($this->configurer->fresh()->can(self::DUTY), 'The raw row is a grant in the permission store…');
        try {
            $this->draft();
            $this->fail('The shipped manifest keeps the operation disabled.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, RoundingPolicyVersion::query()->count());

        $registry = app(PermissionRegistry::class);
        $this->assertNotContains(self::DUTY, $registry->all(), 'It is not registered, so a re-sync can never grant it.');
        $this->assertNotContains(self::DUTY, $registry->automaticGrants());
        $this->artisan('erp:sync-admin-permissions', ['email' => User::factory()->create(['email' => 'admin@example.com'])->email])->assertSuccessful();
        $this->assertFalse(User::where('email', 'admin@example.com')->firstOrFail()->can(self::DUTY));
    }

    public function test_only_a_holder_of_the_duty_in_unrestricted_scope_may_create_or_activate(): void
    {
        $this->enableDuty();
        $draft = $this->draft();
        $stranger = User::factory()->create();
        $limited = User::factory()->create();
        $limited->givePermissionTo(self::DUTY);
        DB::table('access_scope_limits')->insert(['company_id' => 1, 'user_id' => $limited->id, 'permission' => self::DUTY, 'scope' => 'MY_UNITS', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([$stranger, $limited->fresh()] as $who) {
            $this->assertThrows(fn () => $this->draft(by: $who), AuthorizationException::class);
            $this->assertThrows(fn () => $this->activate($draft, $this->today(), $who), AuthorizationException::class);
        }
        $this->assertSame(1, RoundingPolicyVersion::query()->count());
        $this->assertSame('DRAFT', $draft->fresh()->status);
    }

    public function test_a_draft_records_who_and_why_numbers_itself_and_governs_nothing(): void
    {
        $this->enableDuty();

        $first = $this->draft(2, SettlementRounding::HalfEven, '  Quote in whole cents  ');
        $second = $this->draft(3, SettlementRounding::Down, 'Quote in mills');

        $this->assertSame([1, 2], [$first->version_no, $second->version_no]);
        $this->assertSame(['DRAFT', 2, 'HALF_EVEN', 'Quote in whole cents', $this->configurer->id], [$first->status, $first->unit_price_scale, $first->rounding_mode, $first->reason, $first->changed_by]);
        $this->assertNull($first->effective_from);
        $policy = $this->resolve($this->today());
        $this->assertTrue($policy->isBuiltIn(), 'A draft is not in force.');
        $this->assertSame(4, $policy->unitPriceScale);
    }

    public function test_an_unsound_draft_is_refused(): void
    {
        $this->enableDuty();
        foreach ([1, 5, 0] as $scale) {
            $this->assertSame('ROUNDING_POLICY_INVALID', $this->refusal(fn () => $this->draft($scale)));
        }
        foreach (['', '   ', str_repeat('x', 1001)] as $reason) {
            $this->assertSame('ROUNDING_POLICY_INVALID', $this->refusal(fn () => $this->draft(reason: $reason)));
        }
        $this->assertSame(0, RoundingPolicyVersion::query()->count());
    }

    public function test_activation_puts_the_version_in_force_from_its_date_and_the_default_applies_before_it(): void
    {
        $this->enableDuty();
        $from = $this->day(5);
        $active = $this->activate($this->draft(2, SettlementRounding::HalfEven), $from);

        $this->assertSame(['ACTIVE', $from, null, $this->configurer->id], [$active->status, $active->effective_from->format('Y-m-d'), $active->effective_end_exclusive, $active->activated_by]);
        $this->assertNotNull($active->activated_at);
        $this->assertNull($active->previous_version_id);

        $before = $this->resolve($this->day(4));
        $this->assertTrue($before->isBuiltIn(), 'Until the effective date, what was in force still is.');
        $this->assertSame(4, $before->unitPriceScale);
        $onDay = $this->resolve($from);
        $this->assertSame([1, 2, SettlementRounding::HalfEven], [$onDay->versionNo, $onDay->unitPriceScale, $onDay->rounding]);
        $this->assertSame($onDay->fingerprint(), $this->resolve($this->day(400))->fingerprint(), 'The head is open-ended.');
    }

    public function test_a_successor_closes_its_predecessor_at_its_own_date_without_overlap(): void
    {
        $this->enableDuty();
        $v1 = $this->activate($this->draft(4), $this->today());
        $changeDay = $this->day(10);
        $v2 = $this->activate($this->draft(2, SettlementRounding::Down, 'Move to whole cents'), $changeDay);

        $v1 = $v1->fresh();
        $this->assertSame(['SUPERSEDED', $changeDay], [$v1->status, $v1->effective_end_exclusive->format('Y-m-d')]);
        $this->assertSame(['ACTIVE', $v1->id, null], [$v2->status, $v2->previous_version_id, $v2->effective_end_exclusive]);

        $this->assertSame(1, $this->resolve($this->day(9))->versionNo, 'The last day of the old version.');
        $this->assertSame(2, $this->resolve($changeDay)->versionNo, 'The first day of the new one: never both, never neither.');
        $this->assertSame(1, $this->resolve($this->today())->versionNo);
        $this->assertSame(1, RoundingPolicyVersion::query()->where('status', 'ACTIVE')->count());
    }

    public function test_changing_the_policy_does_not_rewrite_what_the_old_one_produced(): void
    {
        $this->enableDuty();
        $usd = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2]);
        $this->activate($this->draft(4, SettlementRounding::HalfUp), $this->today());
        $date = $this->today();

        $old = $this->resolve($date);
        $before = Arithmetic::settleLine($old->acceptUnitPrice('0.3350'), '3', $old->settlementRule($usd))->settled;
        $fingerprint = $old->fingerprint();

        $this->activate($this->draft(2, SettlementRounding::Down), $this->day(3));

        $again = $this->resolve($date);
        $this->assertSame($fingerprint, $again->fingerprint(), 'The same date still answers with the same rules.');
        $this->assertSame('1.0100', (string) $before);
        $this->assertSame((string) $before, (string) Arithmetic::settleLine($again->acceptUnitPrice('0.3350'), '3', $again->settlementRule($usd))->settled);
        $new = $this->resolve($this->day(3));
        $this->assertSame('0.9900', (string) Arithmetic::settleLine($new->acceptUnitPrice('0.33'), '3', $new->settlementRule($usd))->settled, 'The successor applies to new work under its own rules.');
    }

    public function test_the_policy_supplies_the_settlement_rule_of_each_currency(): void
    {
        $this->enableDuty();
        $this->activate($this->draft(3, SettlementRounding::HalfEven), $this->today());
        $policy = $this->resolve($this->today());
        $make = fn (string $code, int $places) => Currency::firstOrCreate(['code' => $code], ['name' => $code, 'symbol' => $code, 'decimal_places' => $places]);

        foreach (['JPY' => [0, '1.0000'], 'USD' => [2, '1.0000'], 'KWD' => [3, '1.0050'], 'CLF' => [4, '1.0050']] as $code => [$places, $settled]) {
            $rule = $policy->settlementRule($make($code, $places));
            $this->assertSame($places, $rule->quantum->decimalPlaces);
            $this->assertSame(SettlementRounding::HalfEven->mode(), $rule->mode);
            $this->assertSame($settled, (string) Arithmetic::settleLine('0.3350', '3', $rule)->settled, $code);
        }
    }

    public function test_activation_refuses_a_non_draft_a_past_date_a_date_not_after_the_head_and_a_bad_date(): void
    {
        $this->enableDuty();
        $v1 = $this->activate($this->draft(), $this->day(2));
        $next = $this->draft(3);

        $this->assertSame('ROUNDING_POLICY_NOT_DRAFT', $this->refusal(fn () => $this->activate($v1, $this->day(9))));
        $this->assertSame('ROUNDING_POLICY_EFFECTIVE_DATE_INVALID', $this->refusal(fn () => $this->activate($next, $this->day(-1))), 'No backdating.');
        $this->assertSame('ROUNDING_POLICY_EFFECTIVE_DATE_INVALID', $this->refusal(fn () => $this->activate($next, $this->day(2))), 'Not after the version it replaces.');
        $this->assertSame('ROUNDING_POLICY_EFFECTIVE_DATE_INVALID', $this->refusal(fn () => $this->activate($next, $this->day(1))));
        $this->assertSame('FINANCIAL_DATE_INVALID', $this->refusal(fn () => $this->activate($next, '2026-02-30')));
        $this->assertSame('FINANCIAL_DATE_INVALID', $this->refusal(fn () => $this->activate($next, 'soon')));
        $this->assertSame('DRAFT', $next->fresh()->status);
        $this->assertSame('ACTIVE', $v1->fresh()->status, 'A refused activation closes nothing.');
        $this->assertNull($v1->fresh()->effective_end_exclusive);

        $this->activate($next, $this->day(3));
        $this->assertSame(2, $this->resolve($this->day(3))->versionNo);
    }

    public function test_a_failed_activation_rolls_back_completely(): void
    {
        $this->enableDuty();
        $v1 = $this->activate($this->draft(), $this->today());
        $next = $this->draft(2);
        // Fail the second write: the predecessor has already been closed when the draft is activated, so a leak would show here.
        DB::unprepared("CREATE FUNCTION sales_rounding_policy_test_boom() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
            IF NEW.status = 'ACTIVE' AND NEW.version_no = 2 THEN RAISE EXCEPTION 'boom'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER sales_rounding_policy_test_boom BEFORE UPDATE ON sales_rounding_policy_versions FOR EACH ROW EXECUTE FUNCTION sales_rounding_policy_test_boom()");

        $this->assertThrows(fn () => $this->activate($next, $this->day(5)), QueryException::class);

        $this->assertSame(['ACTIVE', null], [$v1->fresh()->status, $v1->fresh()->effective_end_exclusive]);
        $this->assertSame('DRAFT', $next->fresh()->status);
        $this->assertSame(1, $this->resolve($this->day(9))->versionNo, 'Nothing changed in force.');
    }

    public function test_an_activated_version_is_immutable_permanent_and_cannot_be_inserted_active(): void
    {
        $this->enableDuty();
        $v1 = $this->activate($this->draft(4), $this->today());
        $v2 = $this->activate($this->draft(2), $this->day(4));
        $v1 = $v1->fresh();

        foreach ([['unit_price_scale' => 3], ['rounding_mode' => 'UP'], ['reason' => 'changed'], ['effective_from' => $this->day(-30)], ['version_no' => 9], ['changed_by' => User::factory()->create()->id]] as $change) {
            $this->assertDbRefuses(fn () => DB::table('sales_rounding_policy_versions')->where('id', $v1->id)->update($change));
            $this->assertDbRefuses(fn () => DB::table('sales_rounding_policy_versions')->where('id', $v2->id)->update($change));
        }
        $this->assertDbRefuses(fn () => DB::table('sales_rounding_policy_versions')->where('id', $v1->id)->delete());
        $this->assertDbRefuses(fn () => DB::table('sales_rounding_policy_versions')->where('id', $v2->id)->delete());
        // Re-opening a closed version, or closing the head by hand, is not the successor's closing step.
        $this->assertDbRefuses(fn () => DB::table('sales_rounding_policy_versions')->where('id', $v1->id)->update(['status' => 'ACTIVE', 'effective_end_exclusive' => null]));
        $this->assertDbRefuses(fn () => DB::table('sales_rounding_policy_versions')->where('id', $v1->id)->update(['effective_end_exclusive' => $this->day(2)]));
        // Closing the head by hand, with no successor behind it, is refused when the transaction ends (the check is deferred so the real activation can close first).
        DB::statement('SET CONSTRAINTS sales_rounding_policy_successor IMMEDIATE');
        $this->assertDbRefuses(fn () => DB::table('sales_rounding_policy_versions')->where('id', $v2->id)->update(['status' => 'SUPERSEDED', 'effective_end_exclusive' => $this->day(8)]));

        // A row cannot be born active, or skip the draft stage, around the Action.
        $this->assertDbRefuses(fn () => DB::table('sales_rounding_policy_versions')->insert(['company_id' => 1, 'version_no' => 50, 'unit_price_scale' => 4, 'rounding_mode' => 'HALF_UP', 'status' => 'ACTIVE',
            'effective_from' => $this->day(20), 'reason' => 'x', 'changed_by' => $this->configurer->id, 'activated_by' => $this->configurer->id, 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]));
        $this->assertSame(2, RoundingPolicyVersion::query()->count());
        $this->assertSame(4, $v1->unit_price_scale);
    }

    public function test_a_draft_may_be_discarded_but_history_may_not(): void
    {
        $this->enableDuty();
        $draft = $this->draft();
        DB::table('sales_rounding_policy_versions')->where('id', $draft->id)->delete();
        $this->assertSame(0, RoundingPolicyVersion::query()->count());
    }

    public function test_the_database_allows_one_open_ended_head_and_valid_shapes_only(): void
    {
        $this->enableDuty();
        $this->activate($this->draft(), $this->today());
        $other = $this->draft(3);

        $this->assertDbRefuses(fn () => DB::table('sales_rounding_policy_versions')->where('id', $other->id)->update(['status' => 'ACTIVE', 'effective_from' => $this->day(5), 'activated_by' => $this->configurer->id, 'activated_at' => now()]));
        foreach ([['unit_price_scale' => 1], ['unit_price_scale' => 5], ['rounding_mode' => 'CEILING'], ['rounding_mode' => 'UNNECESSARY'], ['status' => 'BOGUS'], ['reason' => '  ']] as $bad) {
            $this->assertDbRefuses(fn () => DB::table('sales_rounding_policy_versions')->where('id', $other->id)->update($bad));
        }
        $this->assertSame('DRAFT', $other->fresh()->status);
    }

    public function test_versions_and_numbering_are_isolated_by_company(): void
    {
        $this->enableDuty();
        $other = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $stranger = DB::table('users')->insertGetId(['company_id' => $other, 'name' => 'S', 'email' => 's@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_rounding_policy_versions')->insert(['company_id' => $other, 'version_no' => 1, 'unit_price_scale' => 2, 'rounding_mode' => 'DOWN', 'status' => 'DRAFT', 'reason' => 'theirs', 'changed_by' => $stranger, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_rounding_policy_versions')->where('company_id', $other)->update(['status' => 'ACTIVE', 'effective_from' => '2020-01-01', 'activated_by' => $stranger, 'activated_at' => now()]);

        $this->assertTrue($this->resolve($this->today())->isBuiltIn(), 'Another company\'s active policy never governs this one.');
        $mine = $this->draft();
        $this->assertSame(1, $mine->version_no, 'Version numbers are per company.');
        $theirs = RoundingPolicyVersion::withoutGlobalScopes()->where('company_id', $other)->firstOrFail();
        $this->assertSame($this->refusal(fn () => $this->activate($theirs, $this->today())), 'ACTOR_OUTSIDE_COMPANY');
        $this->assertSame(2, RoundingPolicyVersion::withoutGlobalScopes()->count());

        // The resolver follows the active company: seen from the other company, its own policy applies.
        $this->app->instance(CompanyContext::class, new class($other) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $seen = app(ResolveRoundingPolicy::class)($this->today());
        $this->assertSame([false, 2, $other], [$seen->isBuiltIn(), $seen->unitPriceScale, $seen->companyId]);
        $this->assertSame($other, RoundingPolicyVersion::query()->firstOrFail()->company_id);
    }

    public function test_drafts_prepared_before_a_change_are_marked_not_rewritten(): void
    {
        $this->enableDuty();
        $this->activate($this->draft(4), $this->today());
        $bound = RoundingPolicyBinding::capture($this->resolve($this->today()), $this->today());
        $prices = ['a' => '0.3350', 'b' => '2.50'];

        $this->assertFalse(DraftRevalidation::review($bound, $this->resolve($this->today()), $prices)->requiresReview());
        $this->activate($this->draft(2, SettlementRounding::HalfUp), $this->day(2));

        $review = DraftRevalidation::review($bound, $this->resolve($this->day(2)), $prices);
        $this->assertTrue($review->requiresReview());
        $this->assertSame(['a'], $review->nonConforming);
        $this->assertFalse(DraftRevalidation::review($bound, $this->resolve($this->day(1)), $prices)->requiresReview(), 'Before the change takes effect the draft is still current.');
    }

    public function test_rolling_the_migration_back_refuses_to_erase_activated_history(): void
    {
        $this->enableDuty();
        $migration = require base_path('database/migrations/2026_10_16_090000_create_sales_rounding_policy_versions.php');
        $this->activate($this->draft(), $this->today());

        $this->assertThrows(fn () => $migration->down(), \RuntimeException::class);
        $this->assertSame(1, RoundingPolicyVersion::query()->count());
    }

    public function test_the_resolver_refuses_a_malformed_date(): void
    {
        $this->assertSame('FINANCIAL_DATE_INVALID', $this->refusal(fn () => $this->resolve('16/10/2026')));
    }
}
