<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationAvailability;
use App\Modules\Sales\Support\Quotation\QuotationDraftOutcome;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;

/**
 * Starts a successor DRAFT of an issued revision (QL5): same family, the issued revision named as its explicit predecessor, and the offered scope carried over as inputs — the
 * logical scopes, quantities, sources, memberships and choice groups. Nothing that was true at issue is carried as if still true: no entered price or FX evidence, no tax, no
 * dates, no approval and no number, so the successor must be priced, dated and issued afresh. The predecessor stays exactly as available as before; a family has one open
 * successor draft; a predecessor already replaced is not revised again. Guarded by `sales.quotation.prepare` (STAGED) and an active member, judged again inside the operation.
 */
final class CreateQuotationRevision
{
    public function __construct(private readonly QuotationOperation $operations) {}

    public function __invoke(User $actor, int $predecessorRevisionId, ?string $requestKey): QuotationDraftOutcome
    {
        $this->authorise($actor);
        $context = SalesActorContext::forUser($actor, 'quotation.create_revision');

        $outcome = $this->operations->run(
            $context, 'quotation.create_revision', $requestKey, ['predecessor_revision_id' => $predecessorRevisionId],
            fn (): QuotationRevision => app(QuotationAccess::class)->requireRevision($actor, $predecessorRevisionId, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE], true),
            fn (QuotationRevision $predecessor): array => $this->create($actor, $predecessor),
        );

        return QuotationDraftOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }

    /** @return array<string, mixed> */
    private function create(User $actor, QuotationRevision $predecessor): array
    {
        if (! $predecessor->isIssued() || QuotationAvailability::of($predecessor) === QuotationAvailability::REPLACED) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_PREDECESSOR_INVALID, 'A revision starts from an issued quotation that has not been replaced.', 'predecessor_revision_id');
        }
        if (QuotationRevision::query()->where('family_id', $predecessor->family_id)->where('state', 'DRAFT')->whereNotNull('predecessor_revision_id')->exists()) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_SUCCESSOR_EXISTS, 'This quotation already has a successor draft; finish or cancel it first.', 'predecessor_revision_id');
        }
        $revision = QuotationRevision::create([
            'family_id' => $predecessor->family_id, 'predecessor_revision_id' => $predecessor->id, 'recipient_party_id' => $predecessor->recipient_party_id,
            'currency_id' => $predecessor->currency_id, 'created_by' => $actor->id,
        ])->refresh();
        foreach (QuotationDraftLine::query()->where('revision_id', $predecessor->id)->orderBy('sort_order')->orderBy('id')->get() as $line) {
            QuotationDraftLine::create([
                'family_id' => $revision->family_id, 'revision_id' => $revision->id, 'logical_scope_id' => $line->logical_scope_id, 'sort_order' => $line->sort_order,
                'section_title' => $line->section_title, 'description' => $line->description, 'membership' => $line->membership, 'choice_group_id' => $line->choice_group_id,
                'is_baseline' => $line->is_baseline, 'source_kind' => $line->source_kind, 'item_id' => $line->item_id, 'assembly_version_id' => $line->assembly_version_id,
                'manual_measure' => $line->manual_measure, 'offered_quantity' => $line->offered_quantity, 'offered_uom_id' => $line->offered_uom_id, 'created_by' => $actor->id,
            ]);
        }

        $revision->refresh();

        return QuotationDraftOutcome::result($revision->family_id, $revision->id, $revision->input_generation);
    }

    private function authorise(User $actor): void
    {
        // Capability and record access are judged inside the operation by QuotationAccess, under the company access fence; this is only the early refusal of a non-member.
        ActiveActor::require($actor, 'use quotations');
    }
}
