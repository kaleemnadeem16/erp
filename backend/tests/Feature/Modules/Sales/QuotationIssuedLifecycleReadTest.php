<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\CancelQuotationDraft;
use App\Modules\Sales\Actions\WithdrawQuotationOffer;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\Quotation\QuotationAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

/**
 * What the issued-quotation read model and the quotation list say about an offer's life: the official number, the derived availability (never stored), and who may see which draft
 * for readiness (OWN vs ALL) — so a screen can show "issued", "withdrawn", "expired" or a cancelled draft without guessing.
 */
class QuotationIssuedLifecycleReadTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        Permission::findOrCreate(P::QUOTATION_WITHDRAW, 'web');
    }

    private function issue(QuotationRevision $draft): string
    {
        Sanctum::actingAs($this->checker);

        return (string) $this->postJson("/api/v1/quotations/{$draft->id}/issue", ['expected_generation' => QuotationRevision::findOrFail($draft->id)->input_generation], ['Idempotency-Key' => $this->issueKey()])
            ->assertCreated()->json('data.official_number');
    }

    public function test_the_issued_record_says_available_and_then_withdrawn_without_changing_anything_else(): void
    {
        $draft = $this->preparedDraft();
        $number = $this->issue($draft);

        $this->getJson("/api/v1/quotations/{$draft->id}/issued")->assertOk()->assertJsonPath('data.availability', QuotationAvailability::AVAILABLE)->assertJsonPath('data.official_number', $number);

        $names = [P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::QUOTATION_WITHDRAW];
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest(array_map(fn (SalesDuty $d): SalesDuty => in_array($d->name, $names, true)
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate) : $d, SalesDutyManifest::shipped()->all())));
        $manager = User::factory()->create();
        $manager->givePermissionTo([P::QUOTATION_VIEW, P::QUOTATION_WITHDRAW]);
        app(WithdrawQuotationOffer::class)($manager, $draft->id, 'w-lifecycle', 'Priced in error');

        Sanctum::actingAs($this->checker);
        $this->getJson("/api/v1/quotations/{$draft->id}/issued")->assertOk()->assertJsonPath('data.availability', QuotationAvailability::WITHDRAWN)->assertJsonPath('data.official_number', $number);
    }

    public function test_the_list_carries_the_official_number_and_the_derived_availability_and_filters_by_state(): void
    {
        $open = $this->preparedDraft();
        $cancelled = $this->preparedDraft();
        $issued = $this->preparedDraft();
        $number = $this->issue($issued);
        app(CancelQuotationDraft::class)($this->maker, $cancelled->id, QuotationRevision::findOrFail($cancelled->id)->input_generation, $this->issueKey(), 'Not needed');

        Sanctum::actingAs($this->checker);
        $rows = collect($this->getJson('/api/v1/quotations?per_page=100')->assertOk()->json('data'))->keyBy('revision_id');
        $this->assertSame([QuotationAvailability::DRAFT, null], [$rows[$open->id]['availability'], $rows[$open->id]['official_number']]);
        $this->assertSame([QuotationAvailability::CANCELLED_DRAFT, null], [$rows[$cancelled->id]['availability'], $rows[$cancelled->id]['official_number']]);
        $this->assertSame([QuotationAvailability::AVAILABLE, $number], [$rows[$issued->id]['availability'], $rows[$issued->id]['official_number']]);

        $only = $this->getJson('/api/v1/quotations?filter[state]=ISSUED')->assertOk()->json('data');
        $this->assertSame([$issued->id], array_column($only, 'revision_id'));
        $this->assertSame('ISSUED', $only[0]['state']);
        foreach (['cost', 'margin', 'fingerprint', 'tax_evidence'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, (string) json_encode($only));
        }
    }

    public function test_readiness_is_refused_for_a_cancelled_draft_and_hidden_from_a_preparer_limited_to_their_own(): void
    {
        $draft = $this->preparedDraft();
        $other = User::factory()->create();
        $other->givePermissionTo([P::QUOTATION_PREPARE, P::QUOTATION_VIEW]);
        AccessScopeLimit::create(['user_id' => $other->id, 'permission' => P::QUOTATION_VIEW, 'scope' => 'OWN']);
        AccessScopeLimit::create(['user_id' => $other->id, 'permission' => P::QUOTATION_PREPARE, 'scope' => 'OWN']);

        // ALL (the maker has no limit and prepared it) reads; a stranger limited to OWN gets the same not-found as an unknown id.
        Sanctum::actingAs($this->maker);
        $this->getJson("/api/v1/quotations/{$draft->id}/issue-readiness")->assertOk()->assertJsonPath('data.issue_ready', true);
        Sanctum::actingAs($other);
        $hidden = $this->getJson("/api/v1/quotations/{$draft->id}/issue-readiness")->assertNotFound();
        $unknown = $this->getJson('/api/v1/quotations/999999/issue-readiness')->assertNotFound();
        $this->assertSame($unknown->json('message'), $hidden->json('message'));

        app(CancelQuotationDraft::class)($this->maker, $draft->id, QuotationRevision::findOrFail($draft->id)->input_generation, $this->issueKey(), 'Not needed');
        Sanctum::actingAs($this->maker);
        $this->getJson("/api/v1/quotations/{$draft->id}/issue-readiness")->assertStatus(422)->assertJsonPath('reason_code', 'QUOTATION_NOT_EDITABLE');
    }
}
