<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationFamilyAssociationEvent;
use App\Modules\Sales\Models\QuotationOwnershipEvent;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\OpportunityOutcome;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\DraftHeader;
use App\Modules\Sales\Support\Quotation\QuotationAssociation;
use App\Modules\Sales\Support\Quotation\QuotationDraftOutcome;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Modules\Sales\Support\Quotation\QuotationOwnership;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CompanyContext;

/**
 * Starts a quotation: a new family with its first DRAFT revision. Nothing is priced, numbered or issued, and a draft may be incomplete — the recipient Party,
 * the dates and every line can follow. The currency is explicit and defaults to the company base currency. The creator becomes the family's first responsible owner, with an INITIAL ownership event. Linking an Opportunity needs the preparer duty AND an
 * independent legitimate read of the pipeline, each judged by its own scope; the link is recorded as the family's first association event (ADR 0099).
 * Guarded by `sales.quotation.prepare` — a STAGED duty: until it is activated `SalesAccess` refuses every caller. A retry with the same key replays.
 */
final class CreateQuotationDraft
{
    public function __construct(private readonly CompanyContext $company, private readonly QuotationOperation $operations) {}

    /** @param  array<string, mixed>  $input  recipient_party_id, currency_id, pricing_date, tax_estimate_date, valid_through_local_date, opportunity_id */
    public function __invoke(User $actor, ?string $requestKey, array $input): QuotationDraftOutcome
    {
        SalesAccess::require($actor, PermissionVocabulary::QUOTATION_PREPARE, 'prepare a quotation');
        $context = SalesActorContext::forUser($actor, 'quotation.create_draft');

        $header = DraftHeader::parse($input, ['opportunity_id']);
        $opportunityId = $input['opportunity_id'] ?? null;
        if ($opportunityId !== null) {
            SalesAccess::requireRead($actor);
            $opportunity = is_int($opportunityId) && $opportunityId > 0 ? Opportunity::query()->find($opportunityId) : null;
            if ($opportunity === null) {
                throw QuotationRefusal::make(QuotationRefusal::OPPORTUNITY_NOT_FOUND, 'The Opportunity was not found.', 'opportunity_id');
            }
            if ($opportunity->outcome !== OpportunityOutcome::Open) {
                throw QuotationRefusal::make(QuotationRefusal::OPPORTUNITY_NOT_OPEN, 'A quotation can only be linked to an open Opportunity.', 'opportunity_id');
            }
            QuotationAssociation::assertRecipientMatches($opportunity->party_id, $header['recipient_party_id'] ?? null);
        }
        $header['currency_id'] ??= Company::query()->findOrFail($this->company->id())->base_currency_id
            ?? throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'The company has no base currency, so a currency must be chosen.', 'currency_id');
        DraftHeader::verifyReferences($header);

        $outcome = $this->operations->run(
            $context, 'quotation.create_draft', $requestKey, ['header' => $header, 'opportunity_id' => $opportunityId],
            static fn () => null,
            function () use ($actor, $header, $opportunityId): array {
                // The creator is the family's first owner (a suspended creator cannot be), recorded as its INITIAL ownership event in this same transaction.
                $owner = QuotationOwnership::eligibleOwner($actor->id);
                $family = QuotationFamily::create([
                    'created_by' => $actor->id, 'current_opportunity_id' => $opportunityId, 'association_generation' => $opportunityId === null ? 0 : 1,
                    'responsible_owner_id' => $owner->id, 'ownership_generation' => 1, 'access_generation' => 0, 'access_baseline_state' => 'NATIVE',
                ]);
                QuotationOwnershipEvent::create(['family_id' => $family->id, 'generation' => 1, 'kind' => QuotationOwnershipEvent::INITIAL, 'new_owner_id' => $owner->id,
                    'access_generation' => 0, 'actor_id' => $actor->id, 'reason' => 'Quotation created', 'recorded_at' => now()]);
                if ($opportunityId !== null) {
                    QuotationFamilyAssociationEvent::create(['family_id' => $family->id, 'generation' => 1, 'old_opportunity_id' => null, 'new_opportunity_id' => $opportunityId,
                        'actor_id' => $actor->id, 'occurred_at' => now()]);
                }
                $revision = QuotationRevision::create($header + ['family_id' => $family->id, 'created_by' => $actor->id])->refresh();

                return QuotationDraftOutcome::result($family->id, $revision->id, $revision->input_generation);
            },
        );

        return QuotationDraftOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }
}
