<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

/** What a draft write reports: where the draft is and the input generation it reached. A replay reports the original result, with `replayed` set. */
final readonly class QuotationDraftOutcome
{
    public function __construct(public int $familyId, public int $revisionId, public int $inputGeneration, public bool $replayed) {}

    /** @param  array<string, mixed>  $result */
    public static function fromReceipt(array $result, bool $replayed): self
    {
        return new self((int) $result['family_id'], (int) $result['revision_id'], (int) $result['input_generation'], $replayed);
    }

    /** @return array{v: int, family_id: int, revision_id: int, input_generation: int} */
    public static function result(int $familyId, int $revisionId, int $inputGeneration): array
    {
        return ['v' => 1, 'family_id' => $familyId, 'revision_id' => $revisionId, 'input_generation' => $inputGeneration];
    }
}
