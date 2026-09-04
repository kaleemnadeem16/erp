<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Recalculation\AvcoCounterfactualReplayer;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RecalculationApproval;
use App\Modules\Inventory\Models\RecalculationImpactVersion;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Decides one exact RecalculationImpactVersion — never the parent request
 * generally, per ADR 0040. Requester/approver separation is absolute (no
 * small-company self-review exception: that's Q13's negative-stock immediate
 * review only, not this Q14 approval). Revalidates by re-running the replay
 * as a precondition of deciding: if the pool's ledger changed since this
 * version was computed, the version is marked stale and a replacement is
 * appended under the same request, requiring fresh review — deciding never
 * proceeds against numbers that are no longer accurate.
 */
final class DecideRecalculationApproval
{
    public function __construct(private readonly AvcoCounterfactualReplayer $replayer) {}

    public function __invoke(RecalculationImpactVersion $version, RecalculationApprovalDecision $decision, string $notes, User $actor): RecalculationApproval
    {
        if (trim($notes) === '') {
            throw new DomainInvariantException('An approval decision requires notes.', 'notes');
        }

        // Revalidation may need to commit a stale-flip + replacement version
        // even on the path that ultimately still refuses the decision — a
        // thrown exception here would roll the transaction back and silently
        // discard the replacement, defeating the whole point of generating
        // it. So the closure returns null as a "regenerated" sentinel instead
        // of throwing, and the actual exception is raised below, once that
        // commit has already landed.
        $outcome = DB::transaction(function () use ($version, $decision, $notes, $actor): RecalculationApproval|null {
            $request = RecalculationRequest::query()->lockForUpdate()->findOrFail($version->recalculation_request_id);
            $version = RecalculationImpactVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($version->is_stale) {
                throw new DomainInvariantException("This impact version is stale — review the request's current version instead.", 'impact_version_id');
            }
            if ($request->requested_by === $actor->id) {
                throw new DomainInvariantException('A recalculation request must be decided by a different user.', 'decided_by');
            }
            if (! $actor->can('inventory.recalculation.approve')) {
                throw new DomainInvariantException('The actor is not permitted to decide recalculation requests.', 'decided_by');
            }
            $existing = RecalculationApproval::query()->where('impact_version_id', $version->id)->first();
            if ($existing !== null) {
                if ($existing->decision !== $decision || $existing->decided_by !== $actor->id || $existing->notes !== trim($notes)) {
                    throw new DomainInvariantException('This impact version already has a different approval decision.', 'impact_version_id');
                }

                return $existing;
            }

            $item = Item::query()->findOrFail($request->item_id);
            $location = Location::query()->findOrFail($request->location_id);
            $batch = $request->batch_id === null ? null : StockBatch::query()->find($request->batch_id);
            $fresh = $this->replayer->replay($item, $location, $batch, BigDecimal::of($request->qty), BigDecimal::of($request->base_unit_cost), $request->moved_at);
            if (! $fresh->valuationAdjustment()->isEqualTo(BigDecimal::of($version->valuation_adjustment))) {
                $version->update(['is_stale' => true]);
                $nextVersionNo = (int) RecalculationImpactVersion::query()->where('recalculation_request_id', $request->id)->max('version_no') + 1;
                RecalculationImpactVersion::query()->create([
                    'recalculation_request_id' => $request->id, 'version_no' => $nextVersionNo, 'avco_pool_id' => $fresh->pool->id,
                    'original_qty_on_hand' => (string) $fresh->originalQtyOnHand,
                    'original_inventory_value' => (string) $fresh->originalInventoryValue,
                    'original_avg_unit_cost' => (string) $fresh->originalAvgUnitCost,
                    'counterfactual_qty_on_hand' => (string) $fresh->counterfactualQtyOnHand,
                    'counterfactual_inventory_value' => (string) $fresh->counterfactualInventoryValue,
                    'counterfactual_avg_unit_cost' => (string) $fresh->counterfactualAvgUnitCost,
                    'valuation_adjustment' => (string) $fresh->valuationAdjustment(),
                    'computed_at' => now(), 'is_stale' => false,
                ]);

                return null;
            }

            $company = Company::query()->findOrFail(app(CompanyContext::class)->id());

            return RecalculationApproval::query()->create([
                'impact_version_id' => $version->id, 'decision' => $decision, 'notes' => trim($notes),
                'decided_by' => $actor->id, 'decided_at' => now(),
                'expires_at' => $decision === RecalculationApprovalDecision::Approved ? now()->addHours($company->recalculation_approval_ttl_hours) : null,
            ]);
        });

        if ($outcome === null) {
            throw new DomainInvariantException('The economic impact changed since this version was computed — a new version was generated and requires fresh review.', 'impact_version_id');
        }

        return $outcome;
    }
}
