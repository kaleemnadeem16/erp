<?php

namespace Tests\Feature\Modules\Sales\Concerns;

use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\PartyContact;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\PreviewCustomerAcceptance;
use App\Modules\Sales\Actions\RecordCustomerAcceptance;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionComponent;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as P;

/** Genuine issued service → consent through owning Actions. Tax/controls fixtures are installed by the enclosing issuing test. */
trait BuildsHandoverFixture
{
    protected function handoverDuties(): array
    {
        return [P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::QUOTATION_WITHDRAW, P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_PREPARE];
    }

    protected function activateHandover(): void
    {
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped()->withActivated($this->handoverDuties()));
    }

    protected function serviceAcceptance(User $actor, int $partyId, string $sourceKind = 'SERVICE_ITEM'): array
    {
        $uom = Uom::create(['code' => 'SERVICE-EA', 'name' => 'Service commercial unit', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false]);
        $category = Category::create(['code' => 'SERVICE', 'name' => 'Services']);
        $item = Item::create(['code' => 'SERVICE', 'name' => 'Fixed service deliverable', 'type' => $sourceKind === 'SERVICE_ITEM' ? 'SERVICE' : 'RAW', 'stock_uom_id' => $uom->id, 'is_stocked' => false,
            'is_sellable' => true, 'is_purchasable' => false, 'valuation_method' => 'FIFO', 'tracking' => 'NONE', 'category_id' => $category->id]);
        $today = now('Asia/Karachi')->toDateString();
        $draft = app(CreateQuotationDraft::class)($actor, 'service-draft', ['recipient_party_id' => $partyId, 'pricing_date' => $today, 'tax_estimate_date' => $today, 'valid_through_local_date' => now('Asia/Karachi')->addMonth()->toDateString()]);
        app(UpdateQuotationDraft::class)($actor, $draft->revisionId, $draft->inputGeneration, 'service-line', ['add_lines' => [['source_kind' => $sourceKind, 'item_id' => $item->id,
            'offered_quantity' => '2', 'unit_price' => '100.0000']]]);
        $revision = QuotationRevision::findOrFail($draft->revisionId);
        app(IssueQuotation::class)($actor, $revision->id, $revision->input_generation, 'service-issue');
        $line = QuotationIssuedLine::where('revision_id', $revision->id)->firstOrFail();
        $case = CustomerDecisionCase::create(['party_id' => $partyId, 'intake_identity' => 'Synthetic service consent', 'recorded_by' => $actor->id]);
        $component = CustomerDecisionComponent::create(['case_id' => $case->id, 'intent_identity' => 'service', 'family_id' => $line->family_id, 'original_scope_anchor_id' => $line->logical_scope_id, 'recorded_by' => $actor->id]);
        $e = CustomerDecisionEvidence::create(['case_id' => $case->id, 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'DOCUMENT_ON_FILE', 'customer_representative_snapshot' => 'Synthetic buyer', 'occurrence_precision' => 'UNKNOWN', 'factual_description' => 'The buyer agrees to the full issued service unchanged.', 'observation_state' => 'OBSERVED', 'recorded_by' => $actor->id]);
        $args = [$revision->id, $component->id, $e->id, $line->id, 0, 0, null];
        $token = app(PreviewCustomerAcceptance::class)($actor, ...$args)['preview_token'];
        $accepted = app(RecordCustomerAcceptance::class)($actor, 'service-accept', ...[...$args, $token, true]);
        $contact = PartyContact::create(['party_id' => $partyId, 'name' => 'Synthetic delivery contact', 'email' => 'service@example.invalid']);

        return ['acceptance' => $accepted['acceptance_id'], 'revision' => $revision->id, 'item' => $item->id, 'contact' => $contact->id, 'case' => $case->id,
            'inputs' => ['location_kind' => 'REMOTE', 'contact_id' => $contact->id, 'instructions' => 'Arrange service with the named contact.']];
    }
}
