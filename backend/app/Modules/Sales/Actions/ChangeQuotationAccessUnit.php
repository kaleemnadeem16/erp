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
 * Sets or clears the family's reserved access unit. This is storage and evidence only: no unit is inferred from memberships, nothing reads the unit for visibility
 * and a unit confers no manager access. The owner is untouched. Same authority, generation, reason and replay protocol as `ReassignQuotationOwner`; naming the
 * current unit (or none when none is set) changes nothing and records nothing.
 */
final class ChangeQuotationAccessUnit
{
    public function __construct(private readonly QuotationOperation $operations) {}

    public function __invoke(User $actor, int $familyId, ?int $unitId, int $expectedOwnershipGeneration, int $expectedAccessGeneration, ?string $requestKey, string $reason): QuotationOwnershipOutcome
    {
        QuotationOwnership::requireAuthority($actor);
        $context = SalesActorContext::forUser($actor, 'quotation.change_access_unit');
        $reason = QuotationOwnership::reason($reason);

        $outcome = $this->operations->run(
            $context, 'quotation.change_access_unit', $requestKey,
            ['family_id' => $familyId, 'access_unit_id' => $unitId, 'expected_ownership_generation' => $expectedOwnershipGeneration, 'expected_access_generation' => $expectedAccessGeneration, 'reason' => $reason],
            fn (): QuotationFamily => QuotationOwnership::lockFamily($familyId),
            function (QuotationFamily $family) use ($actor, $unitId, $expectedOwnershipGeneration, $expectedAccessGeneration, $reason): array {
                QuotationOwnership::requireResolved($family);
                QuotationOwnership::requireGenerations($family, $expectedOwnershipGeneration, $expectedAccessGeneration);
                $owner = (int) $family->responsible_owner_id;
                if ($family->access_unit_id === $unitId) {
                    return QuotationOwnershipOutcome::result($family->id, $owner, $unitId, $family->ownership_generation, $family->access_generation, false);
                }
                $unit = $unitId === null ? null : QuotationOwnership::activeUnit($unitId);
                QuotationOwnership::append($family, QuotationOwnershipEvent::UNIT_CHANGED, $owner, $unit?->id, $actor, $reason);

                return QuotationOwnershipOutcome::result($family->id, $owner, $unit?->id, $family->ownership_generation, $family->access_generation, true);
            },
        );

        return QuotationOwnershipOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }
}
