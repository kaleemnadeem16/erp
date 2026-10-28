<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Support\AssemblyCostEvidence;
use App\Modules\Estimating\Support\AssemblyVersionStatus;
use App\Modules\Estimating\Support\CostEvidenceAuthorities;
use App\Modules\Estimating\Support\CostEvidenceFailure;
use App\Modules\Estimating\Support\CostEvidenceWorkflow;
use App\Modules\Inventory\Actions\QueryMaterialCost;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Throwable;

/**
 * The cost-blind evidence seam (#29a): the cost of ONE active Assembly version for ONE work quantity, read with exactly the costing the roll-up already uses
 * (`ExplodeAssemblyVersion::forWorkflow` inside one read-only cost scenario) — no second costing engine — and returned as `AssemblyCostEvidence`, whose figures
 * only the named, authorised workflow can open. It deliberately does NOT ask for `estimating.internal_cost.view` and grants nobody anything. The authority is a runtime verdict: the owning workflow
 * registers a `CostEvidenceAuthority` (an allowlist of one workflow, `CostEvidenceWorkflow`), and before any cost is read the seam asks it whether THIS person may
 * use the workflow right now — a workflow with no authority is refused, and naming the workflow grants nothing. The single consumer is also pinned by
 * `CostEvidenceSeamHasOneCallerTest`. Read-only and company-scoped.
 *
 * Whatever cannot be established is evidence of that, not an error and never a zero: an unknown, foreign or inactive version or an unusable quantity is UNKNOWN
 * with a fixed reason code, and partial or unknown costing keeps its PARTIAL or UNKNOWN certainty. Business refusals from the costing are folded into
 * `COST_EVIDENCE_UNAVAILABLE` without their text (it can name items, rates or amounts); any other failure becomes `CostEvidenceFailure`, with a fixed message and
 * nothing chained. Nothing here logs.
 */
final class ReadAssemblyCostEvidence
{
    public function __construct(
        private readonly QueryMaterialCost $materialCost,
        private readonly ExplodeAssemblyVersion $explode,
        private readonly CompanyContext $company,
        private readonly CostEvidenceAuthorities $authorities,
    ) {}

    public function __invoke(int $assemblyVersionId, string $workQuantity, CostEvidenceWorkflow $workflow, User $actor): AssemblyCostEvidence
    {
        if ($actor->company_id !== $this->company->id()) {
            throw new DomainInvariantException('The actor is outside the active company.', 'actor', 'ACTOR_OUTSIDE_COMPANY');
        }
        // The owning workflow's own verdict on this person, right now, before any cost is read; the enum alone is not authority.
        $this->authorities->authorise($workflow, $actor);
        try {
            $quantity = $this->quantity($workQuantity);
            if ($quantity === null) {
                return AssemblyCostEvidence::unavailable($assemblyVersionId, AssemblyCostEvidence::WORK_QUANTITY_INVALID);
            }
            $version = AssemblyVersion::query()->with('assembly')->find($assemblyVersionId);
            if ($version === null || $version->assembly->company_id !== $this->company->id()) {
                return AssemblyCostEvidence::unavailable($assemblyVersionId, AssemblyCostEvidence::ASSEMBLY_VERSION_NOT_FOUND);
            }
            if ($version->status !== AssemblyVersionStatus::Active) {
                return AssemblyCostEvidence::unavailable($version->id, AssemblyCostEvidence::ASSEMBLY_VERSION_NOT_ACTIVE);
            }
            $breakdown = $this->materialCost->scenario(fn (CostReadSession $session) => $this->explode->forWorkflow($version, $session, $quantity));

            return AssemblyCostEvidence::from($version->id, $breakdown->cost);
        } catch (DomainInvariantException) {
            return AssemblyCostEvidence::unavailable($assemblyVersionId, AssemblyCostEvidence::COST_EVIDENCE_UNAVAILABLE);
        } catch (Throwable) {
            throw new CostEvidenceFailure;
        }
    }

    /** A positive plain decimal of at most six places (the technical scale of Assembly work); anything else is not a quantity. */
    private function quantity(string $text): ?BigDecimal
    {
        if (preg_match('/^\d{1,13}(\.\d{1,6})?$/', $text) !== 1) {
            return null;
        }
        $quantity = BigDecimal::of($text);

        return $quantity->isPositive() ? $quantity : null;
    }
}
