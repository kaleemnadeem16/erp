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

/**
 * Makes another active member accountable for a quotation family. The previous owner stays in the history, including one who has since been suspended; the new
 * owner is granted nothing — ownership is responsibility, not access — and `created_by` never changes. The caller states the ownership and access generations it
 * saw (a family that has moved is refused as stale) and a reason; a retry of a completed request replays before that check, and naming the current owner changes
 * nothing and records nothing. A family with no recorded owner (UNRESOLVED) is refused: it needs an approved baseline capture. Guarded by `sales.quotation.assign`
 * AND an independent `sales.quotation.view`, both STAGED: until they are activated `SalesAccess` refuses every caller, and a restricted grant is refused outright.
 */
final class ReassignQuotationOwner
{
    public function __construct(private readonly QuotationOperation $operations) {}

    public function __invoke(User $actor, int $familyId, int $newOwnerId, int $expectedOwnershipGeneration, int $expectedAccessGeneration, ?string $requestKey, string $reason): QuotationOwnershipOutcome
    {
        QuotationOwnership::requireAuthority($actor);
        $context = SalesActorContext::forUser($actor, 'quotation.reassign_owner');
        $reason = QuotationOwnership::reason($reason);

        $outcome = $this->operations->run(
            $context, 'quotation.reassign_owner', $requestKey,
            ['family_id' => $familyId, 'owner_id' => $newOwnerId, 'expected_ownership_generation' => $expectedOwnershipGeneration, 'expected_access_generation' => $expectedAccessGeneration, 'reason' => $reason],
            fn (): QuotationFamily => QuotationOwnership::lockFamily($familyId),
            function (QuotationFamily $family) use ($actor, $newOwnerId, $expectedOwnershipGeneration, $expectedAccessGeneration, $reason): array {
                QuotationOwnership::requireResolved($family);
                QuotationOwnership::requireGenerations($family, $expectedOwnershipGeneration, $expectedAccessGeneration);
                if ($family->responsible_owner_id === $newOwnerId) {
                    return QuotationOwnershipOutcome::result($family->id, $newOwnerId, $family->access_unit_id, $family->ownership_generation, $family->access_generation, false);
                }
                $owner = QuotationOwnership::eligibleOwner($newOwnerId);
                QuotationOwnership::append($family, QuotationOwnershipEvent::REASSIGNED, $owner->id, $family->access_unit_id, $actor, $reason);

                return QuotationOwnershipOutcome::result($family->id, $owner->id, $family->access_unit_id, $family->ownership_generation, $family->access_generation, true);
            },
        );

        return QuotationOwnershipOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }
}
