<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

/** What an ownership write reports: the family's owner, reserved unit and generations afterwards, and whether anything changed. A replay reports the original result. */
final readonly class QuotationOwnershipOutcome
{
    public function __construct(
        public int $familyId,
        public int $ownerId,
        public ?int $accessUnitId,
        public int $ownershipGeneration,
        public int $accessGeneration,
        public bool $changed,
        public bool $replayed,
    ) {}

    /** @param  array<string, mixed>  $result */
    public static function fromReceipt(array $result, bool $replayed): self
    {
        return new self((int) $result['family_id'], (int) $result['owner_id'], $result['access_unit_id'] === null ? null : (int) $result['access_unit_id'],
            (int) $result['ownership_generation'], (int) $result['access_generation'], (bool) $result['changed'], $replayed);
    }

    /** @return array{v: int, family_id: int, owner_id: int, access_unit_id: int|null, ownership_generation: int, access_generation: int, changed: bool} */
    public static function result(int $familyId, int $ownerId, ?int $accessUnitId, int $ownershipGeneration, int $accessGeneration, bool $changed): array
    {
        return ['v' => 1, 'family_id' => $familyId, 'owner_id' => $ownerId, 'access_unit_id' => $accessUnitId, 'ownership_generation' => $ownershipGeneration,
            'access_generation' => $accessGeneration, 'changed' => $changed];
    }
}
