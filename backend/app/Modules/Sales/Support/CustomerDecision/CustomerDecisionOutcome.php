<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\CustomerDecision;

/** What an intake operation reports: the case and its generation (the expected head for the next change). Nothing about the evidence. */
final readonly class CustomerDecisionOutcome
{
    public function __construct(public int $caseId, public int $generation, public ?int $evidenceId, public bool $replayed) {}

    /** @param  array<string, mixed>  $result */
    public static function fromReceipt(array $result, bool $replayed): self
    {
        return new self((int) $result['case_id'], (int) $result['generation'], isset($result['evidence_id']) ? (int) $result['evidence_id'] : null, $replayed);
    }

    /** @return array{v: int, case_id: int, generation: int, evidence_id?: int} */
    public static function result(int $caseId, int $generation, ?int $evidenceId = null): array
    {
        return ['v' => 1, 'case_id' => $caseId, 'generation' => $generation] + ($evidenceId === null ? [] : ['evidence_id' => $evidenceId]);
    }
}
