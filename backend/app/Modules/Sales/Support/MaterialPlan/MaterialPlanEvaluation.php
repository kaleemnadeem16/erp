<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\MaterialPlan;

/**
 * One evaluation of a Material Plan: its per-material rows, the strongest control effect, the actor-independent routing fingerprint, what stops it being adopted, and the
 * approval subjects it needs. Internal: `items` and `approvals` carry internal-cost figures and are never a response.
 */
final readonly class MaterialPlanEvaluation
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array{code: string, item_id: int}>  $problems
     * @param  list<array{item_id: int, subject_code: string, facts: array<string, mixed>}>  $approvals
     * @param  array<string, mixed>  $provenance  the exact technical sources the plan was resolved from
     */
    public function __construct(public array $items, public string $effect, public string $fingerprint, public array $problems, public array $approvals, public ?int $recipientPartyId = null, public ?int $customerId = null, public array $provenance = []) {}

    /** @return list<string> */
    public function problemCodes(): array
    {
        return array_values(array_unique(array_column($this->problems, 'code')));
    }
}
