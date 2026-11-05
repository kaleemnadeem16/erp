<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

/** Stable, non-commercial receipt envelope; no authority epochs or other recipients' evidence. */
final readonly class QuotationShareOutcome
{
    public function __construct(public int $familyId, public ?int $grantId, public int $ownershipGeneration, public int $accessGeneration, public bool $changed, public bool $replayed) {}

    /** @param array<string,mixed> $result */
    public static function fromReceipt(array $result, bool $replayed): self
    {
        return new self((int) $result['family_id'], $result['grant_id'] === null ? null : (int) $result['grant_id'], (int) $result['ownership_generation'], (int) $result['access_generation'], (bool) $result['changed'], $replayed);
    }

    /** @return array{v:int,family_id:int,grant_id:int|null,ownership_generation:int,access_generation:int,changed:bool} */
    public static function result(int $familyId, ?int $grantId, int $ownership, int $access, bool $changed): array
    {
        return ['v' => 1, 'family_id' => $familyId, 'grant_id' => $grantId, 'ownership_generation' => $ownership, 'access_generation' => $access, 'changed' => $changed];
    }
}
