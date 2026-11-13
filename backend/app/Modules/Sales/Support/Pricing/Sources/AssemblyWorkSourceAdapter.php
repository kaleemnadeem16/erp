<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing\Sources;

use App\Exceptions\DomainInvariantException;
use App\Modules\Estimating\Actions\ExplodeAssemblyVersion;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Support\AssemblyVersionStatus;
use App\Modules\Estimating\Support\CostReadAuthorization;
use App\Modules\Inventory\Actions\QueryMaterialCost;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Pricing\PricingInput;
use App\Modules\Sales\Support\Pricing\PricingQuantity;
use App\Modules\Sales\Support\Pricing\PricingRefusal;
use App\Modules\Sales\Support\Pricing\PricingSourceAdapter;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Pricing\ResolvedSource;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Assembly work, from one pinned ACTIVE version. The only supported cost adapter today is the guarded Estimating roll-up, so the actor must hold the
 * existing cost-read duty (`CostReadAuthorization`, run here exactly as `ExplodeAssemblyVersion::__invoke` does); an actor without it is refused, not
 * served a blind or zero cost. A cost-blind evaluation Action is a later, separately authorised slice. The priced unit must be the Assembly's work unit.
 */
final class AssemblyWorkSourceAdapter implements PricingSourceAdapter
{
    public function __construct(
        private readonly ExplodeAssemblyVersion $explode,
        private readonly QueryMaterialCost $materialCost,
        private readonly CostReadAuthorization $authorise,
        private readonly CompanyContext $company,
    ) {}

    public function kind(): PricingSourceKind
    {
        return PricingSourceKind::AssemblyWork;
    }

    /**
     * Proves the source and quantity and nothing else: no cost is read and no estimating duty is needed. This is what a quotation draft stores, because a seller may
     * prepare Assembly work without being allowed to see what it costs (owner decision 2026-11-13); the cost is read only through the guarded cost-blind seam.
     * The pinned version is returned as it is, so a later activation of a newer version never changes which version a stored line names.
     */
    public function identify(PricingInput $input): ResolvedSource
    {
        [$version, $quantity] = $this->proven($input);

        return $this->resolved($version, $quantity, FinancialResult::unknown([['code' => 'ASSEMBLY_COST_NOT_READ']]), ['cost_source' => 'none', 'version_id' => $version->id]);
    }

    public function resolve(PricingInput $input): ResolvedSource
    {
        [$version, $quantity] = $this->proven($input);
        try {
            ($this->authorise)($input->actor->actor);
        } catch (AuthorizationException|DomainInvariantException $refused) {
            throw PricingRefusal::make(PricingRefusal::SOURCE_EVIDENCE_NOT_AUTHORISED, 'Pricing Assembly work needs permission to read estimating cost.', 'source');
        }
        $breakdown = $this->materialCost->scenario(fn (CostReadSession $session) => $this->explode->forWorkflow($version, $session, $quantity));

        return $this->resolved($version, $quantity, FinancialResult::fromCost($breakdown->cost), ['cost_source' => 'estimating.explode-assembly-version', 'version_id' => $version->id]);
    }

    /** @return array{0: AssemblyVersion, 1: BigDecimal} */
    private function proven(PricingInput $input): array
    {
        $version = AssemblyVersion::query()->with('assembly')->find($input->source->id);
        if ($version === null || $version->assembly->company_id !== $this->company->id()) {
            throw PricingRefusal::make(PricingRefusal::SOURCE_NOT_FOUND, 'The assembly version was not found.', 'source');
        }
        if ($version->status !== AssemblyVersionStatus::Active) {
            throw PricingRefusal::make(PricingRefusal::SOURCE_INCOMPATIBLE, 'Only an active assembly version can be priced.', 'source');
        }
        if ($input->sellingUomId === null || $input->sellingUomId !== $version->assembly->work_uom_id) {
            throw PricingRefusal::make(PricingRefusal::SELLING_UNIT_UNSUPPORTED, 'Assembly work is priced in the Assembly\'s work unit.', 'selling_uom_id');
        }

        return [$version, PricingQuantity::forAssemblyWork($input->quantity)];
    }

    /** @param  array<string, mixed>  $evidence */
    private function resolved(AssemblyVersion $version, BigDecimal $quantity, FinancialResult $cost, array $evidence): ResolvedSource
    {
        return new ResolvedSource($this->kind(), $version->id, $version->assembly->code.' v'.$version->version_no.' — '.$version->assembly->name,
            $version->assembly->work_uom_id, $quantity, $cost, $evidence);
    }
}
