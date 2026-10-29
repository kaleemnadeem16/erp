<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Issue\IssueBlocker;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxProvider;
use App\Support\RateType;
use App\Support\Status;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Sales\FakeQuotationControlsProvider;
use Tests\Support\Sales\FakeQuotationTaxProvider;
use Tests\TestCase;

/**
 * QL3 groundwork — `AssessQuotationIssueReadiness`: a draft's issue blockers as codes only. Every header fact, every line through the preview the preparer already
 * sees, and the two independent evidence checks (tax, controls) whose real implementations plug in later. Unknown is never a pass; nothing is written.
 */
class QuotationIssueReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const PREPARE = PermissionVocabulary::QUOTATION_PREPARE;

    private User $preparer;

    private Currency $pkr;

    private Currency $usd;

    private Party $party;

    private string $today;

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate(self::PREPARE, 'web');
        $this->pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        $this->usd = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'symbol' => '$', 'decimal_places' => 2]);
        Company::findOrFail(1)->update(['base_currency_id' => $this->pkr->id]);
        $this->today = now(Company::findOrFail(1)->timezone)->format('Y-m-d');
        $this->party = Party::create(['code' => 'PROSPECT', 'name' => 'Prospect Ltd']);
        $this->preparer = User::factory()->create();
        $this->preparer->givePermissionTo(self::PREPARE);
        $duties = array_map(fn (SalesDuty $duty): SalesDuty => $duty->name === self::PREPARE
            ? new SalesDuty($duty->name, $duty->class, DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty, SalesDutyManifest::shipped()->all());
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));
    }

    private function key(): string
    {
        return 'key-'.++$this->keys;
    }

    private function draft(array $header = [], array $lines = []): int
    {
        $outcome = app(CreateQuotationDraft::class)($this->preparer, $this->key(), $header);
        if ($lines !== []) {
            app(UpdateQuotationDraft::class)($this->preparer, $outcome->revisionId, $outcome->inputGeneration, $this->key(), ['add_lines' => $lines]);
        }

        return $outcome->revisionId;
    }

    private function line(string $description = 'Survey', ?string $price = '100.0000'): array
    {
        return ['source_kind' => 'MANUAL', 'description' => $description, 'manual_measure' => 'job', 'offered_quantity' => '2', 'unit_price' => $price];
    }

    /** @return array{revision_id: int, input_generation: int, issue_ready: bool, blockers: list<array{code: string, scope: string, line_id: int|null}>} */
    private function assess(int $revision, ?User $by = null): array
    {
        return app(AssessQuotationIssueReadiness::class)($by ?? $this->preparer, $revision);
    }

    /** @return list<string> */
    private function codes(array $result): array
    {
        return array_map(fn (array $blocker): string => $blocker['code'].($blocker['line_id'] === null ? '' : '@'.$blocker['line_id']), $result['blockers']);
    }

    private function evidenceReady(): void
    {
        $this->app->instance(QuotationTaxProvider::class, new FakeQuotationTaxProvider);
        $this->app->instance(QuotationControlsProvider::class, new FakeQuotationControlsProvider);
    }

    private function prepared(?array $lines = null): int
    {
        return $this->draft(['recipient_party_id' => $this->party->id, 'pricing_date' => $this->today, 'valid_through_local_date' => now()->addMonth()->format('Y-m-d')], $lines ?? [$this->line()]);
    }

    public function test_an_empty_draft_names_every_missing_fact_and_is_not_ready(): void
    {
        $result = $this->assess($this->draft());

        $this->assertFalse($result['issue_ready']);
        $this->assertEqualsCanonicalizing(['RECIPIENT_REQUIRED', 'PRICING_DATE_REQUIRED', 'VALID_THROUGH_REQUIRED', 'NO_LINES', 'TAX_NOT_DETERMINED', 'CONTROLS_NOT_CONFIGURED'], $this->codes($result));
    }

    public function test_a_fully_prepared_draft_is_blocked_only_by_the_evidence_not_yet_delivered(): void
    {
        $result = $this->assess($this->prepared());

        $this->assertEqualsCanonicalizing(['TAX_NOT_DETERMINED', 'CONTROLS_NOT_CONFIGURED'], $this->codes($result));
        $this->assertFalse($result['issue_ready']);
    }

    public function test_real_tax_and_controls_providers_plug_in_by_rebinding_and_a_prepared_draft_is_then_ready(): void
    {
        $this->evidenceReady();

        $result = $this->assess($this->prepared());

        $this->assertSame([], $this->codes($result), 'The tax and control blockers come from the providers; nothing else adds them.');
        $this->assertTrue($result['issue_ready']);
    }

    public function test_the_providers_are_asked_with_the_lines_settled_nets(): void
    {
        $tax = new FakeQuotationTaxProvider;
        $this->app->instance(QuotationTaxProvider::class, $tax);
        $this->app->instance(QuotationControlsProvider::class, new FakeQuotationControlsProvider);
        $revision = $this->prepared([$this->line('Priced'), $this->line('Unpriced', null)]);
        $lines = QuotationRevision::findOrFail($revision)->lines()->orderBy('id')->get();

        $result = $this->assess($revision);

        $this->assertSame([$lines[0]->id => '200.0000', $lines[1]->id => null], $tax->calls[0], 'a known net is passed as text, an unknown one as null — never as zero');
        $this->assertContains('TAX_NET_UNKNOWN@'.$lines[1]->id, $this->codes($result));
        $this->assertFalse($result['issue_ready']);
    }

    public function test_a_provider_that_blocks_blocks_whatever_the_rest_says(): void
    {
        $this->app->instance(QuotationTaxProvider::class, new FakeQuotationTaxProvider(blockers: [new IssueBlocker('TAX_UNKNOWN_FOR_LINE', IssueBlocker::TAX, 7)]));
        $this->app->instance(QuotationControlsProvider::class, new FakeQuotationControlsProvider(blockers: [new IssueBlocker('CONTROL_UNEVALUABLE', IssueBlocker::CONTROLS)]));

        $codes = $this->codes($this->assess($this->prepared()));

        $this->assertEqualsCanonicalizing(['TAX_UNKNOWN_FOR_LINE@7', 'CONTROL_UNEVALUABLE'], $codes);
    }

    public function test_a_line_without_a_price_is_blocked_by_line(): void
    {
        $revision = $this->prepared([$this->line('Priced'), $this->line('Unpriced', null)]);
        $lines = QuotationRevision::findOrFail($revision)->lines()->orderBy('id')->get();

        $result = $this->assess($revision);

        $this->assertContains('PRICE_NOT_ENTERED@'.$lines[1]->id, $this->codes($result));
        $this->assertNotContains('PRICE_NOT_ENTERED@'.$lines[0]->id, $this->codes($result));
    }

    public function test_the_validity_date_is_required_and_cannot_be_in_the_past(): void
    {
        $past = $this->draft(['recipient_party_id' => $this->party->id, 'pricing_date' => $this->today, 'valid_through_local_date' => now()->subDays(2)->format('Y-m-d')], [$this->line()]);
        $today = $this->draft(['recipient_party_id' => $this->party->id, 'pricing_date' => $this->today, 'valid_through_local_date' => $this->today], [$this->line()]);

        $this->assertContains('VALID_THROUGH_PAST', $this->codes($this->assess($past)));
        $this->assertNotContains('VALID_THROUGH_PAST', $this->codes($this->assess($today)), 'Valid through today is still valid.');
    }

    public function test_an_archived_recipient_blocks_issue_without_failing_the_assessment(): void
    {
        $revision = $this->prepared();
        $this->party->update(['status' => Status::Archived->value]);

        $codes = $this->codes($this->assess($revision));

        $this->assertContains('RECIPIENT_NOT_ACTIVE', $codes);
    }

    public function test_a_price_in_another_currency_is_blocked_once_its_pinned_rate_has_changed(): void
    {
        $rate = ExchangeRate::create(['from_currency_id' => $this->usd->id, 'to_currency_id' => $this->pkr->id, 'rate' => '280', 'rate_date' => $this->today, 'rate_type' => RateType::Spot, 'source' => 'manual']);
        $revision = $this->draft(['recipient_party_id' => $this->party->id, 'pricing_date' => $this->today, 'currency_id' => $this->usd->id, 'valid_through_local_date' => now()->addMonth()->format('Y-m-d')], [$this->line()]);
        $this->assertNotContains('FX_EVIDENCE_CHANGED@'.QuotationRevision::findOrFail($revision)->lines()->firstOrFail()->id, $this->codes($this->assess($revision)));

        $rate->update(['rate' => '281']);

        $this->assertContains('FX_EVIDENCE_CHANGED@'.QuotationRevision::findOrFail($revision)->lines()->firstOrFail()->id, $this->codes($this->assess($revision)));
    }

    public function test_the_result_carries_codes_only_and_never_a_figure(): void
    {
        $result = $this->assess($this->prepared([$this->line('Distinctive', '98765.4321')]));

        $this->assertEqualsCanonicalizing(['revision_id', 'input_generation', 'issue_ready', 'blockers'], array_keys($result));
        foreach ($result['blockers'] as $blocker) {
            $this->assertEqualsCanonicalizing(['code', 'scope', 'line_id'], array_keys($blocker));
            $this->assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]*$/', $blocker['code']);
        }
        $this->assertStringNotContainsString('98765', (string) json_encode($result));
    }

    public function test_it_is_read_only_and_repeatable(): void
    {
        $revision = $this->prepared();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|drop|create)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $first = $this->assess($revision);
        $second = $this->assess($revision);

        $this->assertSame($first, $second);
        $this->assertSame([], $writes);
    }

    public function test_it_is_refused_while_staged_and_to_anyone_without_a_real_prepare_right(): void
    {
        $revision = $this->prepared();
        $nobody = User::factory()->create();
        $limited = User::factory()->create();
        $limited->givePermissionTo(self::PREPARE);
        AccessScopeLimit::create(['user_id' => $limited->id, 'permission' => self::PREPARE, 'scope' => 'MY_UNITS']);

        foreach ([$nobody, $limited] as $refused) {
            try {
                $this->assess($revision, $refused);
                $this->fail('A caller without an unrestricted prepare right must be refused.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->expectException(AuthorizationException::class);
        $this->assess($revision);
    }

    public function test_an_unknown_or_cancelled_revision_is_refused_with_a_stable_code(): void
    {
        $revision = $this->prepared();
        DB::table('quotation_revisions')->where('id', $revision)->update(['state' => 'CANCELLED_DRAFT', 'cancelled_at' => now(), 'cancelled_by' => $this->preparer->id, 'cancellation_reason' => 'Withdrawn']);

        foreach ([[999999, 'QUOTATION_NOT_FOUND'], [$revision, 'QUOTATION_NOT_EDITABLE']] as [$id, $code]) {
            try {
                $this->assess($id);
                $this->fail('Expected a refusal.');
            } catch (DomainInvariantException $refusal) {
                $this->assertSame($code, $refusal->reasonCode);
            }
        }
    }
}
