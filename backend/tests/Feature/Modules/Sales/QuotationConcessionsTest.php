<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\PublishQuotationControlSet;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Documents\QuotationCustomerProjection;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\Issue\ConfiguredQuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

/**
 * PR4/PR5 — discounts and reasoned final-net overrides on a DRAFT: exact settlement through the kernel, the three duties (discount, override, commercial view) judged by the same
 * family policy, one discount slot, a document discount spread so the header stays the sum of its lines, the recorded approval-needed fact (default-deny), no cost or limit in any
 * reader, and the frozen provenance that lets the customer document reconcile.
 */
class QuotationConcessionsTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private User $discounter;

    private User $overrider;

    private User $configurer;

    private int $pkr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        foreach ([Duty::QUOTATION_DISCOUNT, Duty::QUOTATION_PRICE_OVERRIDE, Duty::FINANCIAL_CONFIGURE] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->maker->givePermissionTo([Duty::QUOTATION_DISCOUNT]);
        $this->discounter = $this->maker;
        $this->overrider = User::factory()->create();
        $this->overrider->givePermissionTo([Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW, Duty::QUOTATION_PRICE_OVERRIDE]);
        $this->configurer = User::factory()->create();
        $this->configurer->givePermissionTo(Duty::FINANCIAL_CONFIGURE);
        $on = [Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW, Duty::QUOTATION_ISSUE, Duty::QUOTATION_DISCOUNT, Duty::QUOTATION_PRICE_OVERRIDE, Duty::FINANCIAL_CONFIGURE];
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest(array_map(fn (SalesDuty $d): SalesDuty => in_array($d->name, $on, true)
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate, $d->scope) : $d, SalesDutyManifest::shipped()->all())));
        $this->pkr = (int) Currency::query()->where('code', 'PKR')->value('id');
    }

    private function edit(int $revision, array $body, ?string $key = null): TestResponse
    {
        return $this->patchJson("/api/v1/quotations/{$revision}", $body + ['expected_generation' => QuotationRevision::findOrFail($revision)->input_generation], ['Idempotency-Key' => $key ?? $this->issueKey()]);
    }

    /** @return array<string, mixed> the preview data */
    private function preview(int $revision): array
    {
        return $this->getJson("/api/v1/quotations/{$revision}/preview")->assertOk()->json('data');
    }

    /** @return array<int, array<string, mixed>> */
    private function lineRows(int $revision): array
    {
        return collect($this->preview($revision)['lines'])->keyBy('id')->all();
    }

    private function firstLineId(QuotationRevision $revision): int
    {
        return (int) $revision->lines()->where('membership', 'BASE')->value('id');
    }

    private function publishLimit(string $kind = 'INPUT_REDUCTION_PERCENT', string $limit = '10', string $mode = 'MANDATORY'): void
    {
        app(PublishQuotationControlSet::class)($this->configurer, [['ref' => 'limit-a', 'kind' => $kind, 'mode' => $mode, 'limit' => $limit] + (str_ends_with($kind, 'PERCENT') ? [] : ['currency_id' => $this->pkr]),
            ['ref' => 'floor-a', 'kind' => 'FLOOR', 'mode' => 'MANDATORY', 'limit' => '1', 'currency_id' => $this->pkr]], 'Test limits');
    }

    public function test_each_discount_kind_settles_exactly_and_keeps_the_list_amount(): void
    {
        $revision = $this->preparedDraft();
        $line = $this->firstLineId($revision);
        Sanctum::actingAs($this->discounter);

        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '12.5']]]])->assertOk();
        $row = $this->lineRows($revision->id)[$line];
        $this->assertSame('200.0000', $row['list_amount']);
        $this->assertSame('175.0000', $row['net']['amount']);
        $this->assertSame('25.0000', $row['adjustment']['amount']);

        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PER_PRICED_UNIT', 'value' => '7.5']]]])->assertOk();
        $this->assertSame('185.0000', $this->lineRows($revision->id)[$line]['net']['amount']);

        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PER_LINE', 'value' => '40']]]])->assertOk();
        $this->assertSame('160.0000', $this->lineRows($revision->id)[$line]['net']['amount']);
        $this->assertSame('160.0000', $this->preview($revision->id)['totals']['net']['amount']);

        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => null]]])->assertOk();
        $this->assertSame('200.0000', $this->lineRows($revision->id)[$line]['net']['amount']);
        $this->assertNull(QuotationRevision::findOrFail($revision->id)->lines()->find($line)->discount_kind);
    }

    public function test_a_discount_larger_than_its_line_or_with_bad_numbers_is_refused(): void
    {
        $revision = $this->preparedDraft();
        $line = $this->firstLineId($revision);
        Sanctum::actingAs($this->discounter);

        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PER_LINE', 'value' => '250']]]])->assertUnprocessable()->assertJsonPath('reason_code', 'DISCOUNT_EXCEEDS_BASE');
        foreach ([['PERCENT', '100.0001'], ['PERCENT', '0'], ['PERCENT', '-5'], ['PER_LINE', '1.00001'], ['PER_LINE', '1e3'], ['BOGUS', '5']] as [$kind, $value]) {
            $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => $kind, 'value' => $value]]]])->assertUnprocessable();
        }
        $this->assertNull(QuotationRevision::findOrFail($revision->id)->lines()->find($line)->discount_kind, 'a refused update leaves nothing behind');
    }

    public function test_the_discount_duty_is_required_for_every_touch_and_a_plain_preparer_cannot_clear_one(): void
    {
        $revision = $this->preparedDraft();
        $line = $this->firstLineId($revision);
        Sanctum::actingAs($this->discounter);
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '5']]]])->assertOk();

        $plain = User::factory()->create();
        $plain->givePermissionTo([Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW]);
        Sanctum::actingAs($plain);
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => null]]])->assertForbidden();
        $this->edit($revision->id, ['document_discount' => ['kind' => 'PERCENT', 'value' => '5']])->assertForbidden();
        $this->edit($revision->id, ['add_lines' => [['source_kind' => 'MANUAL', 'description' => 'X', 'manual_measure' => 'job', 'offered_quantity' => '1', 'discount' => ['kind' => 'PERCENT', 'value' => '1']]]])->assertForbidden();
        // Ordinary edits are untouched by the missing duty, and the existing discount survives them.
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'section_title' => 'Works']]])->assertOk();
        $this->assertSame('190.0000', $this->lineRows($revision->id)[$line]['net']['amount']);
    }

    public function test_an_override_needs_its_own_sensitive_duty_a_reason_and_may_only_reduce(): void
    {
        $revision = $this->preparedDraft();
        $line = $this->firstLineId($revision);
        Sanctum::actingAs($this->discounter);
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'net_override' => ['amount' => '150', 'reason' => 'Goodwill']]]])->assertForbidden();

        Sanctum::actingAs($this->overrider);
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'net_override' => ['amount' => '150', 'reason' => '  ']]]])->assertUnprocessable();
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'net_override' => ['amount' => '250', 'reason' => 'Up']]]])->assertUnprocessable()->assertJsonPath('reason_code', 'OVERRIDE_NOT_ALLOWED');
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'net_override' => ['amount' => '150.005', 'reason' => 'Odd']]]])->assertUnprocessable()->assertJsonPath('reason_code', 'DISCOUNT_PRECISION_EXCEEDED');
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'net_override' => ['amount' => '150', 'reason' => 'Goodwill after site visit']]]])->assertOk();

        $row = $this->lineRows($revision->id)[$line];
        $this->assertSame(['200.0000', '150.0000', '50.0000'], [$row['list_amount'], $row['net']['amount'], $row['adjustment']['amount']]);
        $this->assertSame('Goodwill after site visit', $row['adjustment']['override']['reason']);
        $this->assertSame(['PRICE_OVERRIDE', true], [$row['adjustment']['approval_reason'], $row['adjustment']['approval_required']]);
        $stored = QuotationRevision::findOrFail($revision->id)->lines()->find($line);
        $this->assertSame($this->overrider->id, (int) $stored->override_entered_by);
        $this->assertNotNull($stored->override_entered_at);
    }

    public function test_a_line_takes_a_discount_or_an_override_never_both_and_an_override_needs_a_price(): void
    {
        $revision = $this->preparedDraft();
        $line = $this->firstLineId($revision);
        $both = User::factory()->create();
        $both->givePermissionTo([Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW, Duty::QUOTATION_DISCOUNT, Duty::QUOTATION_PRICE_OVERRIDE]);
        Sanctum::actingAs($both);
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '5'], 'net_override' => ['amount' => '100', 'reason' => 'x']]]])->assertUnprocessable();
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'net_override' => ['amount' => '100', 'reason' => 'x']]]])->assertOk();
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '5']]]])->assertUnprocessable();
        $unpriced = [['source_kind' => 'MANUAL', 'description' => 'To quote', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => null, 'net_override' => ['amount' => '10', 'reason' => 'x']]];
        $this->edit($revision->id, ['add_lines' => $unpriced])->assertUnprocessable()->assertJsonPath('reason_code', 'OVERRIDE_NOT_ALLOWED');
        // The database repeats both rules.
        $this->expectException(QueryException::class);
        DB::table('quotation_draft_lines')->where('id', $line)->update(['discount_kind' => 'PERCENT', 'discount_value' => '5', 'discount_entered_by' => $both->id, 'discount_entered_at' => now()]);
    }

    public function test_changing_the_price_or_quantity_of_an_overridden_line_must_restate_the_override(): void
    {
        $revision = $this->preparedDraft();
        $line = $this->firstLineId($revision);
        Sanctum::actingAs($this->overrider);
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'net_override' => ['amount' => '150', 'reason' => 'Goodwill']]]])->assertOk();

        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'offered_quantity' => '3']]])->assertUnprocessable()->assertJsonPath('reason_code', 'OVERRIDE_BASIS_CHANGED');
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'unit_price' => null]]])->assertUnprocessable()->assertJsonPath('reason_code', 'OVERRIDE_BASIS_CHANGED');
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'offered_quantity' => '3', 'net_override' => ['amount' => '250', 'reason' => 'Restated']]]])->assertOk();
        $this->assertSame('250.0000', $this->lineRows($revision->id)[$line]['net']['amount']);
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'net_override' => null]]])->assertOk();
        $this->assertSame('300.0000', $this->lineRows($revision->id)[$line]['net']['amount']);
    }

    public function test_a_document_discount_is_spread_exactly_over_the_counted_lines_only(): void
    {
        $created = app(CreateQuotationDraft::class)($this->maker, $this->issueKey(), ['recipient_party_id' => $this->customer->id, 'pricing_date' => $this->today()]);
        $lines = array_map(fn (string $n): array => ['source_kind' => 'MANUAL', 'description' => "Line {$n}", 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '100.00'], ['a', 'b', 'c']);
        $lines[] = ['source_kind' => 'MANUAL', 'description' => 'Optional', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '50.00', 'membership' => 'OPTIONAL'];
        Sanctum::actingAs($this->discounter);
        $this->edit($created->revisionId, ['add_lines' => $lines])->assertOk();

        $before = QuotationRevision::findOrFail($created->revisionId)->input_generation;
        $this->edit($created->revisionId, ['document_discount' => ['kind' => 'AMOUNT', 'value' => '0.10']])->assertOk();
        $this->assertGreaterThan($before, QuotationRevision::findOrFail($created->revisionId)->input_generation, 'a document discount moves the input generation');
        $preview = $this->preview($created->revisionId);

        $counted = collect($preview['lines'])->where('counts_in_total', true);
        $this->assertEqualsCanonicalizing(['0.0400', '0.0300', '0.0300'], $counted->pluck('adjustment.document_share')->all());
        $this->assertSame('299.9000', $preview['totals']['net']['amount'], 'the header is the sum of its settled lines');
        $this->assertSame('299.9000', (string) $counted->reduce(fn ($c, $r) => bcadd($c, $r['net']['amount'], 4), '0'));
        $this->assertSame('50.0000', collect($preview['lines'])->firstWhere('counts_in_total', false)['net']['amount'], 'an optional line takes no share');
        $this->assertSame('0.1000', $preview['document_discount']['amount']);

        $this->edit($created->revisionId, ['document_discount' => ['kind' => 'PERCENT', 'value' => '10']])->assertOk();
        $this->assertSame('270.0000', $this->preview($created->revisionId)['totals']['net']['amount']);
        $this->edit($created->revisionId, ['document_discount' => ['kind' => 'AMOUNT', 'value' => '300.01']])->assertUnprocessable()->assertJsonPath('reason_code', 'DISCOUNT_EXCEEDS_BASE');
        $this->assertSame('270.0000', $this->preview($created->revisionId)['totals']['net']['amount'], 'a refused update leaves the previous discount');
        $this->edit($created->revisionId, ['document_discount' => null])->assertOk();
        $this->assertNull($this->preview($created->revisionId)['document_discount']);
        $this->assertSame('300.0000', $this->preview($created->revisionId)['totals']['net']['amount']);
    }

    public function test_approval_is_a_recorded_fact_default_deny_until_a_limit_proves_the_discount_within_it(): void
    {
        $revision = $this->preparedDraft();
        $line = $this->firstLineId($revision);
        Sanctum::actingAs($this->discounter);
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '5']]]])->assertOk();
        $this->assertSame('DISCOUNT_LIMIT_NOT_CONFIGURED', $this->lineRows($revision->id)[$line]['adjustment']['approval_reason'], 'no limit configured is not within a limit');
        $this->assertSame('DISCOUNT_LIMIT_NOT_CONFIGURED', QuotationRevision::findOrFail($revision->id)->lines()->find($line)->approval_reason, 'recorded on the line');

        $this->publishLimit('INPUT_REDUCTION_PERCENT', '10');
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '10']]]])->assertOk();
        $row = $this->lineRows($revision->id)[$line];
        $this->assertSame([false, null], [$row['adjustment']['approval_required'], $row['adjustment']['approval_reason']], 'at the limit is within it');
        $this->assertFalse((bool) QuotationRevision::findOrFail($revision->id)->lines()->find($line)->approval_required);

        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '10.5']]]])->assertOk();
        $this->assertSame('DISCOUNT_ABOVE_LIMIT', $this->lineRows($revision->id)[$line]['adjustment']['approval_reason']);

        $encoded = json_encode($this->preview($revision->id));
        foreach (['"cost', 'margin', 'limit_value', '"limit"', 'threshold'] as $leak) {
            $this->assertStringNotContainsString($leak, $encoded, "no {$leak} in what a preparer reads");
        }
    }

    public function test_an_amount_limit_judges_a_per_line_discount_and_a_document_discount_is_judged_once(): void
    {
        $revision = $this->preparedDraft();
        $line = $this->firstLineId($revision);
        $this->publishLimit('INPUT_REDUCTION_AMOUNT', '30');
        Sanctum::actingAs($this->discounter);
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PER_LINE', 'value' => '30']]]])->assertOk();
        $this->assertFalse($this->lineRows($revision->id)[$line]['adjustment']['approval_required']);
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PER_PRICED_UNIT', 'value' => '16']]]])->assertOk();
        $this->assertSame('DISCOUNT_ABOVE_LIMIT', $this->lineRows($revision->id)[$line]['adjustment']['approval_reason']);

        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => null]], 'document_discount' => ['kind' => 'AMOUNT', 'value' => '20']])->assertOk();
        $this->assertFalse($this->preview($revision->id)['document_discount']['approval_required']);
        $this->edit($revision->id, ['document_discount' => ['kind' => 'AMOUNT', 'value' => '31']])->assertOk();
        $this->assertSame('DISCOUNT_ABOVE_LIMIT', $this->preview($revision->id)['document_discount']['approval_reason']);
    }

    public function test_a_concession_that_needs_approval_blocks_issue_and_one_within_its_limit_is_issued_with_provenance(): void
    {
        $this->app->forgetInstance(QuotationControlsProvider::class);
        $this->app->bind(QuotationControlsProvider::class, ConfiguredQuotationControlsProvider::class);
        $this->publishLimit('INPUT_REDUCTION_PERCENT', '10');
        $revision = $this->preparedDraft();
        $line = $this->firstLineId($revision);
        Sanctum::actingAs($this->discounter);
        $codes = fn (): array => array_map(fn (array $b): string => $b['code'].($b['line_id'] === null ? '' : '@'.$b['line_id']), app(AssessQuotationIssueReadiness::class)($this->maker, $revision->id)['blockers']);

        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '20']]]])->assertOk();
        $this->assertContains("APPROVAL_REQUIRED@{$line}", $codes());
        $this->assertContains("CONTROL_FAILED@{$line}", $codes(), 'the mandatory reduction control now sees the discount instead of being unevaluable');

        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '10']]], 'document_discount' => ['kind' => 'PERCENT', 'value' => '5']])->assertOk();
        $this->assertSame([], $codes());
        $outcome = app(IssueQuotation::class)($this->checker, $revision->id, QuotationRevision::findOrFail($revision->id)->input_generation, $this->issueKey());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $frozen = QuotationIssuedLine::query()->where('issue_id', $outcome->issueId)->where('source_line_id', $line)->firstOrFail();
        $this->assertSame(['DISCOUNT', '20.0000', '9.0000', '171.0000'], [$frozen->line_reduction_kind, (string) $frozen->line_reduction_amount, (string) $frozen->document_reduction_amount, (string) $frozen->net]);
        $revisionModel = QuotationRevision::findOrFail($revision->id);
        $projection = QuotationCustomerProjection::forIssue(QuotationIssue::findOrFail($outcome->issueId), $revisionModel);
        $customerLine = $projection->payload['sections']['lines'][0] ?? null;
        $this->assertSame(['2.0000', '100.0000', '29.0000', '171.0000'], [$customerLine['quantity'], $customerLine['unit_price'], $customerLine['discount'], $customerLine['amount']], 'quantity x price - discount = amount');
        $this->assertStringNotContainsString('approval', json_encode($projection->payload));
    }

    public function test_discount_scope_follows_the_family_policy_and_another_company_is_not_found(): void
    {
        $revision = $this->preparedDraft();
        $line = $this->firstLineId($revision);
        $other = User::factory()->create();
        $other->givePermissionTo([Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW, Duty::QUOTATION_DISCOUNT]);
        foreach ([Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW, Duty::QUOTATION_DISCOUNT] as $duty) {
            AccessScopeLimit::create(['user_id' => $other->id, 'permission' => $duty, 'scope' => 'OWN']);
        }
        Sanctum::actingAs($other);
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '5']]]])->assertNotFound();
        $this->assertNull(QuotationRevision::findOrFail($revision->id)->lines()->find($line)->discount_kind);
        // OWN on prepare/view but company-wide discount is still judged per family: the discount duty is limited to OWN here too, so it is a not-found, not a 403 that names the record.
        $this->getJson("/api/v1/quotations/{$revision->id}/editable")->assertNotFound();
    }

    public function test_a_retry_replays_and_a_stale_generation_is_refused(): void
    {
        $revision = $this->preparedDraft();
        $line = $this->firstLineId($revision);
        Sanctum::actingAs($this->discounter);
        $generation = QuotationRevision::findOrFail($revision->id)->input_generation;
        $body = ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '5']]], 'expected_generation' => $generation];
        $this->patchJson("/api/v1/quotations/{$revision->id}", $body, ['Idempotency-Key' => 'same'])->assertOk();
        $this->patchJson("/api/v1/quotations/{$revision->id}", $body, ['Idempotency-Key' => 'same'])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->patchJson("/api/v1/quotations/{$revision->id}", $body, ['Idempotency-Key' => 'other'])->assertUnprocessable()->assertJsonPath('reason_code', 'QUOTATION_DRAFT_STALE');
        $this->patchJson("/api/v1/quotations/{$revision->id}", ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '6']]], 'expected_generation' => $generation], ['Idempotency-Key' => 'same'])->assertStatus(409);
    }

    public function test_the_editable_read_returns_the_entered_concessions_without_computed_money(): void
    {
        $revision = $this->preparedDraft();
        $line = $this->firstLineId($revision);
        Sanctum::actingAs($this->discounter);
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'discount' => ['kind' => 'PERCENT', 'value' => '5']]], 'document_discount' => ['kind' => 'PERCENT', 'value' => '2']])->assertOk();

        $data = $this->getJson("/api/v1/quotations/{$revision->id}/editable")->assertOk()->json('data');
        $row = collect($data['lines'])->firstWhere('id', $line);
        $this->assertSame(['kind' => 'PERCENT', 'value' => '5.0000'], $row['discount']);
        $this->assertNull($row['net_override']);
        $this->assertSame('DISCOUNT_LIMIT_NOT_CONFIGURED', $row['approval_reason']);
        $this->assertSame('PERCENT', $data['document_discount']['kind']);
        $this->assertArrayNotHasKey('net', $row);
        $this->assertArrayNotHasKey('list_amount', $row);
    }
}
