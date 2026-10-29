<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Models\User;
use LogicException;

/**
 * Who may open cost evidence, workflow by workflow. A workflow with no registered authority is refused (default-deny), so a module that is disabled or not yet
 * delivered cannot be used to reach a cost, and the enum alone grants nothing. Registration is by the owning module's provider; one authority per workflow.
 */
final class CostEvidenceAuthorities
{
    /** @var array<string, CostEvidenceAuthority> */
    private array $authorities = [];

    public function register(CostEvidenceAuthority $authority): void
    {
        $key = $authority->workflow()->value;
        if (isset($this->authorities[$key])) {
            throw new LogicException("Cost evidence workflow [{$key}] already has an authority.");
        }
        $this->authorities[$key] = $authority;
    }

    /** @throws CostEvidenceNotAuthorised when no authority is registered or the owning workflow does not permit this person now */
    public function authorise(CostEvidenceWorkflow $workflow, User $actor): void
    {
        $authority = $this->authorities[$workflow->value] ?? null;
        if ($authority === null || ! $authority->permits($actor)) {
            throw new CostEvidenceNotAuthorised;
        }
    }
}
