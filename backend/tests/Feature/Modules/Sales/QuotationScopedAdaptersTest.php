<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\Company;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\ReassignQuotationOwner;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

class QuotationScopedAdaptersTest extends TestCase
{
    use BuildsQuotationOwnership, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    public function test_own_read_and_write_reject_hidden_and_unknown_without_disclosure(): void
    {
        config(['app.debug' => false]);
        $mine = $this->nativeFamily();
        $other = User::factory()->create();
        $other->givePermissionTo([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE]);
        $theirs = $this->nativeFamily($other);
        foreach ([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE] as $duty) {
            AccessScopeLimit::create(['user_id' => $this->preparer->id, 'permission' => $duty, 'scope' => 'OWN']);
        }
        $mineRevision = $mine->revisions()->firstOrFail();
        $hiddenRevision = $theirs->revisions()->firstOrFail();
        Sanctum::actingAs($this->preparer);

        $this->getJson("/api/v1/quotations/{$mineRevision->id}")->assertOk();
        $this->getJson("/api/v1/quotations/{$mineRevision->id}/editable")->assertOk();
        $hidden = $this->getJson("/api/v1/quotations/{$hiddenRevision->id}")->assertNotFound()->json();
        $unknown = $this->getJson('/api/v1/quotations/9999999')->assertNotFound()->json();
        $this->assertSame($unknown, $hidden);
        $this->patchJson("/api/v1/quotations/{$hiddenRevision->id}", ['expected_generation' => 1, 'tax_estimate_date' => '2026-11-02'], ['Idempotency-Key' => 'hidden'])->assertNotFound();
        $this->patchJson("/api/v1/quotations/{$mineRevision->id}", ['expected_generation' => 1, 'tax_estimate_date' => '2026-11-02'], ['Idempotency-Key' => 'mine'])->assertOk();
    }

    public function test_unrestricted_viewer_sees_all_local_families_but_no_foreign_company_record(): void
    {
        config(['app.debug' => false]);
        $mine = $this->nativeFamily();
        $other = User::factory()->create();
        $other->givePermissionTo([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE]);
        $theirs = $this->nativeFamily($other);
        $foreignFamily = $this->foreignFamilyId();
        $currency = Company::query()->findOrFail(1)->base_currency_id;
        $foreignRevision = DB::table('quotation_revisions')->insertGetId([
            'company_id' => $this->otherCompany, 'family_id' => $foreignFamily, 'currency_id' => $currency,
            'created_by' => $this->otherUser, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($this->assigner);
        $this->getJson('/api/v1/quotations/'.$mine->revisions()->firstOrFail()->id)->assertOk();
        $this->getJson('/api/v1/quotations/'.$theirs->revisions()->firstOrFail()->id)->assertOk();
        $foreign = $this->getJson("/api/v1/quotations/{$foreignRevision}")->assertNotFound()->json();
        $unknown = $this->getJson('/api/v1/quotations/9999999')->assertNotFound()->json();
        $this->assertSame($unknown, $foreign);
    }

    public function test_revoked_owner_cannot_replay_a_previous_update(): void
    {
        $family = $this->nativeFamily();
        foreach ([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE] as $duty) {
            AccessScopeLimit::create(['user_id' => $this->preparer->id, 'permission' => $duty, 'scope' => 'OWN']);
        }
        $revision = $family->revisions()->firstOrFail();
        $payload = ['expected_generation' => 1, 'tax_estimate_date' => '2026-11-02'];
        Sanctum::actingAs($this->preparer);
        $this->patchJson("/api/v1/quotations/{$revision->id}", $payload, ['Idempotency-Key' => 'replay-update'])->assertOk();
        app(ReassignQuotationOwner::class)($this->assigner, $family->id, $this->colleague->id, 1, 0, $this->key(), 'Owner changed');
        $this->patchJson("/api/v1/quotations/{$revision->id}", $payload, ['Idempotency-Key' => 'replay-update'])->assertNotFound();
        $this->getJson("/api/v1/quotations/{$revision->id}")->assertNotFound();
    }

    public function test_create_receipt_is_not_replayed_after_family_responsibility_changes(): void
    {
        foreach ([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE] as $duty) {
            AccessScopeLimit::create(['user_id' => $this->preparer->id, 'permission' => $duty, 'scope' => 'OWN']);
        }
        Sanctum::actingAs($this->preparer);
        $payload = ['recipient_party_id' => $this->prospect->id, 'pricing_date' => $this->today];
        $response = $this->postJson('/api/v1/quotations', $payload, ['Idempotency-Key' => 'create-replay'])->assertCreated();
        $familyId = $response->json('data.family_id');
        app(ReassignQuotationOwner::class)($this->assigner, $familyId, $this->colleague->id, 1, 0, $this->key(), 'Owner changed');
        $this->postJson('/api/v1/quotations', $payload, ['Idempotency-Key' => 'create-replay'])->assertNotFound();
    }

    public function test_prepare_without_view_cannot_preview_or_edit(): void
    {
        $family = $this->nativeFamily();
        $revision = $family->revisions()->firstOrFail();
        $this->preparer->revokePermissionTo(Duty::QUOTATION_VIEW);
        Sanctum::actingAs($this->preparer);
        $this->getJson("/api/v1/quotations/{$revision->id}/editable")->assertForbidden();
        $this->getJson("/api/v1/quotations/{$revision->id}/preview")->assertForbidden();
        $this->patchJson("/api/v1/quotations/{$revision->id}", ['expected_generation' => 1], ['Idempotency-Key' => 'no-view'])->assertForbidden();
    }
}
