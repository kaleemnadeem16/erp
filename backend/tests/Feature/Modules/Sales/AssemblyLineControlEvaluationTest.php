<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Estimating\Actions\ActivateAssemblyVersion;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Sales\Actions\EvaluateAssemblyLineControls;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Financial\Controls\AssemblyCostEvidenceProvider;
use App\Modules\Sales\Support\Financial\Controls\ControlMode;
use App\Modules\Sales\Support\Financial\Controls\NetControl;
use App\Modules\Sales\Support\Financial\Controls\NetControlKind;
use App\Modules\Sales\Support\Financial\Controls\NetControlSet;
use App\Modules\Sales\Support\Financial\Controls\UnavailableAssemblyCostEvidence;
use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\CompanyContext;
use App\Support\RateType;
use App\Support\UomDimension;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * #29b — the Sales cost-blind evaluator: a quotation line's FR4 controls judged against Estimating's cost evidence, returning nothing but outcomes by opaque
 * reference. Cost, margin and thresholds never leave; missing or partial cost is UNEVALUABLE; default-deny while STAGED; no estimating cost duty needed.
 *
 * Distinctive figures so a leak is findable: per work unit the cost is 0.5 kg x 10.0000 + 777.7777 = 782.7777, so three units cost 2348.3331; at a net of 4000.0000
 * the margin is 1651.6669 (41.2917%).
 */
class AssemblyLineControlEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private const PREPARE = PermissionVocabulary::QUOTATION_PREPARE;

    private const SECRETS = ['2348', '1651', '41.29', '782', '777', '1500', '1700', '3500', '4500'];

    private Uom $kg;

    private Uom $sqm;

    private Currency $pkr;

    private Item $pigment;

    private Item $uncosted;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate(self::PREPARE, 'web');
        $this->pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        Company::findOrFail(1)->update(['base_currency_id' => $this->pkr->id]);
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->sqm = Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->pigment = Item::create(['code' => 'ctl-pigment', 'name' => 'Pigment', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $category->id]);
        AvcoPool::create([
            'item_id' => $this->pigment->id, 'scope' => AvcoPoolScope::CompanyItem, 'qty_on_hand' => '100', 'inventory_value' => '1000', 'avg_unit_cost' => '10.0000',
            'base_avg_unit_cost' => '10.0000', 'last_valid_avg_unit_cost' => '10.0000', 'status' => AvcoPoolStatus::Active,
        ]);
        $this->uncosted = Item::create(['code' => 'ctl-unknown', 'name' => 'No cost yet', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $category->id]);
        // A seller holds the quotation preparer duty and NO estimating permission whatsoever.
        $this->seller = User::factory()->create();
        $this->seller->givePermissionTo(self::PREPARE);
        $this->activate();
    }

    private function activate(): void
    {
        $duties = array_map(fn (SalesDuty $duty): SalesDuty => $duty->name === self::PREPARE
            ? new SalesDuty($duty->name, $duty->class, DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty, SalesDutyManifest::shipped()->all());
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));
    }

    private function version(array $components, bool $activate = true): AssemblyVersion
    {
        $assembly = Assembly::create(['code' => 'ASM-'.str()->random(6), 'name' => 'Eval Assembly', 'work_uom_id' => $this->sqm->id, 'margin_type' => 'MARKUP', 'margin_pct' => '20']);
        $version = $assembly->versions()->create(['version_no' => 1])->refresh();
        foreach ($components as $sequence => $component) {
            $version->components()->create([...$component, 'sequence' => $sequence]);
        }
        if ($activate) {
            (new ActivateAssemblyVersion)($version, PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']));
        }

        return $version->refresh();
    }

    private function costedVersion(): AssemblyVersion
    {
        return $this->version([
            ['kind' => 'MATERIAL', 'item_id' => $this->pigment->id, 'qty_per_work_unit' => '0.500000', 'uom_id' => $this->kg->id],
            ['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '777.7777'],
        ]);
    }

    private function control(string $id, NetControlKind $kind, string $value, ControlMode $mode = ControlMode::Mandatory): NetControl
    {
        return new NetControl($id, 1, $kind, $mode, $value, $kind->isPercent() ? null : $this->pkr->id);
    }

    private function set(NetControl ...$controls): NetControlSet
    {
        return new NetControlSet(1, 1, array_values($controls));
    }

    private function today(): string
    {
        return now(Company::findOrFail(1)->timezone)->format('Y-m-d');
    }

    /** @return array{outcome: string, blocks_issue: bool, requires_review: bool, controls: list<array{ref: string, outcome: string, blocks_issue: bool}>} */
    private function evaluate(NetControlSet $set, ?int $versionId = null, string $quantity = '3', string $net = '4000.0000', ?User $by = null, ?int $currencyId = null): array
    {
        $versionId ??= $this->costedVersion()->id;

        return app(EvaluateAssemblyLineControls::class)($by ?? $this->seller, $versionId, $quantity, $net, $currencyId ?? $this->pkr->id, $this->today(), $set)->toArray();
    }

    /** @return array<string, string> ref => outcome */
    private function outcomes(array $view): array
    {
        return array_column($view['controls'], 'outcome', 'ref');
    }

    public function test_floor_and_both_margin_controls_pass_and_fail_against_the_real_cost(): void
    {
        $version = $this->costedVersion()->id;
        $view = $this->evaluate($this->set(
            $this->control('c-floor-ok', NetControlKind::Floor, '3500'), $this->control('c-floor-bad', NetControlKind::Floor, '4500'),
            $this->control('c-amt-ok', NetControlKind::MinMarginAmount, '1500'), $this->control('c-amt-bad', NetControlKind::MinMarginAmount, '1700'),
            $this->control('c-pct-ok', NetControlKind::MinMarginPercent, '40'), $this->control('c-pct-bad', NetControlKind::MinMarginPercent, '45'),
        ), $version);

        $this->assertSame(['c-floor-ok' => 'PASS', 'c-floor-bad' => 'FAIL', 'c-amt-ok' => 'PASS', 'c-amt-bad' => 'FAIL', 'c-pct-ok' => 'PASS', 'c-pct-bad' => 'FAIL'], $this->outcomes($view));
        $this->assertSame('FAIL', $view['outcome']);
        $this->assertTrue($view['blocks_issue']);
    }

    public function test_the_limits_are_inclusive_at_the_exact_margin_and_an_advisory_failure_warns_without_blocking(): void
    {
        $version = $this->costedVersion()->id;

        $exact = $this->evaluate($this->set($this->control('c-exact', NetControlKind::MinMarginAmount, '1651.6669')), $version);
        $advisory = $this->evaluate($this->set($this->control('c-advisory', NetControlKind::MinMarginAmount, '1700', ControlMode::Advisory)), $version);

        $this->assertSame(['c-exact' => 'PASS'], $this->outcomes($exact));
        $this->assertSame(['c-advisory' => 'WARN'], $this->outcomes($advisory));
        $this->assertFalse($advisory['blocks_issue']);
        $this->assertTrue($advisory['requires_review']);
    }

    public function test_partial_unknown_or_missing_cost_is_unevaluable_for_cost_controls_and_the_floor_still_judges(): void
    {
        $partial = $this->version([
            ['kind' => 'MATERIAL', 'item_id' => $this->uncosted->id, 'qty_per_work_unit' => '1', 'uom_id' => $this->kg->id],
            ['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '100.0000'],
        ])->id;
        $unknown = $this->version([['kind' => 'MATERIAL', 'item_id' => $this->uncosted->id, 'qty_per_work_unit' => '1', 'uom_id' => $this->kg->id]])->id;
        $draft = $this->version([['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '100.0000']], activate: false)->id;
        $controls = $this->set($this->control('m-amt', NetControlKind::MinMarginAmount, '1'), $this->control('m-pct', NetControlKind::MinMarginPercent, '1'), $this->control('m-floor', NetControlKind::Floor, '100'));

        foreach (['partial' => $partial, 'unknown' => $unknown, 'draft' => $draft, 'not found' => 999999] as $name => $version) {
            $view = $this->evaluate($controls, $version);
            $this->assertSame(['m-amt' => 'UNEVALUABLE', 'm-pct' => 'UNEVALUABLE', 'm-floor' => 'PASS'], $this->outcomes($view), $name);
            $this->assertTrue($view['blocks_issue'], "{$name}: a mandatory control that cannot be evaluated blocks issue");
        }
    }

    public function test_the_cost_is_not_read_when_no_control_needs_it(): void
    {
        $this->app->instance(AssemblyCostEvidenceProvider::class, new class implements AssemblyCostEvidenceProvider
        {
            public function costFor(SalesActorContext $actor, int $assemblyVersionId, BigDecimal $quantity): FinancialResult
            {
                throw new \LogicException('The cost must not be read for a floor-only set.');
            }
        });

        $view = $this->evaluate($this->set($this->control('only-floor', NetControlKind::Floor, '3500')), 999999);

        $this->assertSame(['only-floor' => 'PASS'], $this->outcomes($view));
    }

    public function test_an_unavailable_provider_is_unevaluable_never_a_pass(): void
    {
        $this->app->instance(AssemblyCostEvidenceProvider::class, new UnavailableAssemblyCostEvidence);

        $view = $this->evaluate($this->set($this->control('m-amt', NetControlKind::MinMarginAmount, '1')));

        $this->assertSame(['m-amt' => 'UNEVALUABLE'], $this->outcomes($view));
    }

    public function test_the_result_carries_only_the_safe_fields_and_no_figure(): void
    {
        $view = $this->evaluate($this->set(
            $this->control('c-1', NetControlKind::MinMarginAmount, '1700'), $this->control('c-2', NetControlKind::MinMarginPercent, '45'), $this->control('c-3', NetControlKind::Floor, '4500'),
        ));

        $this->assertEqualsCanonicalizing(['outcome', 'blocks_issue', 'requires_review', 'controls'], array_keys($view));
        foreach ($view['controls'] as $control) {
            $this->assertEqualsCanonicalizing(['ref', 'outcome', 'blocks_issue'], array_keys($control));
        }
        $text = (string) json_encode($view).var_export($view, true).print_r($view, true);
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $text, "the result leaked {$secret}");
        }
    }

    public function test_a_seller_needs_no_estimating_permission_and_gains_none(): void
    {
        $version = $this->costedVersion()->id;
        $before = Permission::query()->count();

        $this->assertFalse($this->seller->can('estimating.internal_cost.view'));
        $this->evaluate($this->set($this->control('c', NetControlKind::MinMarginAmount, '1500')), $version);

        $this->assertFalse($this->seller->fresh()->can('estimating.internal_cost.view'));
        $this->assertSame($before, Permission::query()->count());
        $this->assertSame([self::PREPARE], $this->seller->fresh()->getAllPermissions()->pluck('name')->all());
    }

    public function test_it_is_refused_while_staged_and_to_anyone_without_a_real_prepare_right(): void
    {
        $version = $this->costedVersion()->id;
        $set = $this->set($this->control('c', NetControlKind::Floor, '1'));
        $viewOnly = User::factory()->create();
        Permission::findOrCreate(PermissionVocabulary::QUOTATION_VIEW, 'web');
        $viewOnly->givePermissionTo(PermissionVocabulary::QUOTATION_VIEW);
        $nobody = User::factory()->create();

        foreach ([$viewOnly, $nobody] as $refused) {
            $this->expectRefusal(fn () => $this->evaluate($set, $version, by: $refused));
        }
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->expectRefusal(fn () => $this->evaluate($set, $version));
    }

    private function expectRefusal(callable $call): void
    {
        try {
            $call();
            $this->fail('The evaluation should have been refused.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_preparer_limited_to_my_units_is_refused(): void
    {
        $version = $this->costedVersion()->id;
        $limited = User::factory()->create();
        $limited->givePermissionTo(self::PREPARE);
        AccessScopeLimit::create(['user_id' => $limited->id, 'permission' => self::PREPARE, 'scope' => 'MY_UNITS']);

        $this->expectRefusal(fn () => $this->evaluate($this->set($this->control('c', NetControlKind::Floor, '1')), $version, by: $limited));
    }

    /** @return array<string, array{0: NetControlKind}> */
    public static function unsupportedKinds(): array
    {
        return array_map(fn (NetControlKind $kind): array => [$kind], array_filter(NetControlKind::cases(), fn (NetControlKind $kind): bool => ! in_array($kind, [NetControlKind::Floor, NetControlKind::MinMarginAmount, NetControlKind::MinMarginPercent], true)));
    }

    #[DataProvider('unsupportedKinds')]
    public function test_a_control_kind_outside_the_initial_three_is_refused(NetControlKind $kind): void
    {
        $version = $this->costedVersion()->id;

        try {
            $this->evaluate($this->set($this->control('unsupported', $kind, $kind->isPercent() ? '10' : '10')), $version);
            $this->fail('An unsupported control kind must be refused.');
        } catch (DomainInvariantException $refusal) {
            $this->assertSame('CONTROL_KIND_NOT_SUPPORTED', $refusal->reasonCode);
            $this->assertStringNotContainsString('10', $refusal->getMessage());
        }
    }

    public function test_another_companys_version_is_indistinguishable_from_a_missing_one(): void
    {
        $version = $this->costedVersion()->id;
        $company = DB::table('companies')->insertGetId(['name' => 'Other Company', 'created_at' => now(), 'updated_at' => now()]);
        $other = DB::table('users')->insertGetId(['company_id' => $company, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $outsider = User::query()->findOrFail($other);
        Permission::findOrCreate(self::PREPARE, 'web');
        app(PermissionRegistrar::class)->setPermissionsTeamId($company);
        $outsider->givePermissionTo(Permission::findOrCreate(self::PREPARE, 'web'));
        $set = new NetControlSet($company, 1, [new NetControl('x-margin', 1, NetControlKind::MinMarginAmount, ControlMode::Mandatory, '1', $this->pkr->id)]);
        $this->activate();

        $foreign = app(EvaluateAssemblyLineControls::class)($outsider, $version, '3', '4000.0000', $this->pkr->id, $this->today(), $set)->toArray();
        $missing = app(EvaluateAssemblyLineControls::class)($outsider, 999999, '3', '4000.0000', $this->pkr->id, $this->today(), $set)->toArray();

        $this->assertSame($missing, $foreign);
        $this->assertSame(['x-margin' => 'UNEVALUABLE'], $this->outcomes($foreign));
    }

    public function test_a_control_set_for_another_company_is_refused(): void
    {
        $version = $this->costedVersion()->id;
        $set = new NetControlSet(2, 1, [$this->control('c', NetControlKind::Floor, '1')]);

        $this->expectException(DomainInvariantException::class);
        $this->evaluate($set, $version);
    }

    public function test_a_net_in_another_currency_needs_pinned_fx_or_the_margin_is_unevaluable(): void
    {
        $version = $this->costedVersion()->id;
        $usd = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'symbol' => '$', 'decimal_places' => 2]);
        $fail = new NetControlSet(1, 1, [new NetControl('usd-margin', 1, NetControlKind::MinMarginAmount, ControlMode::Mandatory, '10', $usd->id)]);
        $pass = new NetControlSet(1, 1, [new NetControl('usd-margin', 1, NetControlKind::MinMarginAmount, ControlMode::Mandatory, '8', $usd->id)]);
        $run = fn (NetControlSet $set): array => app(EvaluateAssemblyLineControls::class)($this->seller, $version, '3', '20.0000', $usd->id, $this->today(), $set)->toArray();

        $without = $run($fail);
        // The rate master stores foreign -> company base: 1 USD = 200 PKR, so the 2348.3331 PKR cost is about 11.74 USD against a 20 USD net (margin about 8.26 USD).
        ExchangeRate::create(['from_currency_id' => $usd->id, 'to_currency_id' => $this->pkr->id, 'rate' => '200', 'rate_date' => $this->today(), 'rate_type' => RateType::Spot, 'source' => 'manual']);

        $this->assertSame(['usd-margin' => 'UNEVALUABLE'], $this->outcomes($without), 'No pinned rate: never a guess.');
        $this->assertSame(['usd-margin' => 'FAIL'], $this->outcomes($run($fail)));
        $this->assertSame(['usd-margin' => 'PASS'], $this->outcomes($run($pass)));
    }

    public function test_nothing_is_written_and_nothing_is_logged(): void
    {
        $version = $this->costedVersion()->id;
        $set = $this->set($this->control('c', NetControlKind::MinMarginAmount, '1500'));
        Log::spy();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|drop|create)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->evaluate($set, $version);

        $this->assertSame([], $writes);
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_an_unexpected_failure_of_the_seam_is_unevaluable_and_leaks_nothing(): void
    {
        $version = $this->costedVersion()->id;
        $set = $this->set($this->control('c', NetControlKind::MinMarginAmount, '1500'));
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'avco_pools')) {
                throw new \RuntimeException('secret cost 2348.3331');
            }
        });

        $view = $this->evaluate($set, $version);

        $this->assertSame(['c' => 'UNEVALUABLE'], $this->outcomes($view));
        $this->assertStringNotContainsString('2348', (string) json_encode($view));
    }
}
