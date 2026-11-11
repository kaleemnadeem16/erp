<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\CustomerDecision;

use App\Modules\Sales\Support\Kernel\OperationIntent;

/** @internal The exact full-scope request, excluding the review token and retry key. */
final class AcceptanceIntent
{
    /** @return array<string, int|bool> */
    public static function of(int $revisionId, int $componentId, int $evidenceId, int $lineId, int $caseGeneration, int $scopeGeneration, ?int $choiceGeneration): array
    {
        if (min($revisionId, $componentId, $evidenceId, $lineId) < 1 || min($caseGeneration, $scopeGeneration) < 0 || ($choiceGeneration !== null && $choiceGeneration < 0)) {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::INVALID_INPUT, 'Positive identities and non-negative generations are required.');
        }

        return ['revision_id' => $revisionId, 'component_id' => $componentId, 'evidence_id' => $evidenceId, 'issued_line_id' => $lineId, 'expected_case_generation' => $caseGeneration,
            'expected_scope_generation' => $scopeGeneration, 'expected_choice_generation' => $choiceGeneration ?? -1, 'unchanged_terms' => true];
    }

    /** @param array<string, mixed> $intent */
    public static function digest(array $intent): string
    {
        return OperationIntent::hash('customer_decision.record_acceptance', $intent);
    }
}
