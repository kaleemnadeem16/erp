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
use App\Modules\Sales\Support\Pricing\PricingRefusal;
use App\Modules\Sales\Support\Pricing\PricingSourceAdapter;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Pricing\ResolvedSource;
use App\Support\CompanyContext;
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

    public function resolve(PricingInput $input): ResolvedSource
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
        try {
            ($this->authorise)($input->actor->actor);
        } catch (AuthorizationException|DomainInvariantException $refused) {
            throw PricingRefusal::make(PricingRefusal::SOURCE_EVIDENCE_NOT_AUTHORISED, 'Pricing Assembly work needs permission to read estimating cost.', 'source');
        }
        $breakdown = $this->materialCost->scenario(fn (CostReadSession $session) => $this->explode->forWorkflow($version, $session, $input->quantity));

        return new ResolvedSource($this->kind(), $version->id, $version->assembly->code.' v'.$version->version_no.' — '.$version->assembly->name,
            $version->assembly->work_uom_id, FinancialResult::fromCost($breakdown->cost), ['cost_source' => 'estimating.explode-assembly-version', 'version_id' => $version->id]);
    }
}
