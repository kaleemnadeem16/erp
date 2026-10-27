<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationOwnershipEvent;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Modules\Sales\Support\Quotation\QuotationOwnership;
use App\Modules\Sales\Support\Quotation\QuotationOwnershipOutcome;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;

/**
 * INTERNAL recovery: adopts one legacy (UNRESOLVED) family from an owner-approved mapping — the owner, an optional unit, a source reference and an approval
 * reference. It is rerunnable but never automatic: no migration, route, command or backfill calls it, and it is not an endpoint or a free-form edit. It appends a
 * CAPTURED_BASELINE event stamped with the real capture time and no invented history (the old owner and unit are null), and never copies `created_by` into the owner.
 * Repeating the same mapping is idempotent; a different one on a captured family, or any capture of a NATIVE family, is refused; an inactive, foreign or unknown
 * owner or unit leaves the family UNRESOLVED. Same independent authority (assign + view) as the other ownership Actions.
 */
final class CaptureQuotationAccessBaseline
{
    public function __construct(private readonly QuotationOperation $operations) {}

    public function __invoke(User $actor, int $familyId, int $ownerId, ?int $unitId, string $sourceReference, string $approvalReference, ?string $requestKey, string $reason): QuotationOwnershipOutcome
    {
        QuotationOwnership::requireAuthority($actor);
        $context = SalesActorContext::forUser($actor, 'quotation.capture_access_baseline');
        $reason = QuotationOwnership::reason($reason);
        $source = QuotationOwnership::reference($sourceReference, 'baseline_source_reference');
        $approval = QuotationOwnership::reference($approvalReference, 'approval_reference');

        $outcome = $this->operations->run(
            $context, 'quotation.capture_access_baseline', $requestKey,
            ['family_id' => $familyId, 'owner_id' => $ownerId, 'access_unit_id' => $unitId, 'source' => $source, 'approval' => $approval, 'reason' => $reason],
            fn (): QuotationFamily => QuotationOwnership::lockFamily($familyId),
            function (QuotationFamily $family) use ($actor, $ownerId, $unitId, $source, $approval, $reason): array {
                if ($family->access_baseline_state === 'NATIVE') {
                    throw QuotationRefusal::make(QuotationRefusal::BASELINE_NOT_APPLICABLE, 'This quotation was created with its owner on record; there is no legacy baseline to capture.', 'family_id');
                }
                if ($family->access_baseline_state === 'CAPTURED') {
                    $captured = $family->ownershipEvents()->where('kind', QuotationOwnershipEvent::CAPTURED_BASELINE)->firstOrFail();
                    if ($captured->new_owner_id !== $ownerId || $captured->new_unit_id !== $unitId || $captured->baseline_source_reference !== $source || $captured->approval_reference !== $approval) {
                        throw QuotationRefusal::make(QuotationRefusal::BASELINE_CONFLICT, 'This quotation was already captured from a different mapping.', 'family_id');
                    }

                    return QuotationOwnershipOutcome::result($family->id, (int) $family->responsible_owner_id, $family->access_unit_id, $family->ownership_generation, $family->access_generation, false);
                }
                $owner = QuotationOwnership::eligibleOwner($ownerId);
                $unit = $unitId === null ? null : QuotationOwnership::activeUnit($unitId);
                QuotationOwnership::append($family, QuotationOwnershipEvent::CAPTURED_BASELINE, $owner->id, $unit?->id, $actor, $reason, ['source' => $source, 'approval' => $approval]);

                return QuotationOwnershipOutcome::result($family->id, $owner->id, $unit?->id, $family->ownership_generation, $family->access_generation, true);
            },
        );

        return QuotationOwnershipOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }
}
