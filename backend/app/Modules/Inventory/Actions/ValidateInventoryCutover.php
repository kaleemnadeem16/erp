<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Support\InventoryCutoverBatchStatus;
use App\Modules\Inventory\Support\InventoryCutoverStatus;
use App\Modules\Inventory\Support\WarehouseScope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class ValidateInventoryCutover
{
    public function __construct(
        private readonly RecordInventoryCutoverPolicyTransitions $policyTransitions,
        private readonly InventoryCutoverReconciler $reconciler,
    ) {}

    public function __invoke(InventoryCutover $cutover, User $actor): InventoryCutover
    {
        if (! $actor->can('inventory.cutover.validate')) {
            throw new DomainInvariantException('The actor is not permitted to validate an inventory cutover.', 'validated_by');
        }
        WarehouseScope::requireUnrestricted($actor, 'inventory.cutover.validate', 'validate an inventory cutover');

        return DB::transaction(function () use ($cutover, $actor): InventoryCutover {
            $cutover = InventoryCutover::query()->whereKey($cutover->id)->lockForUpdate()->firstOrFail();
            if ($cutover->status === InventoryCutoverStatus::Validated) {
                return $cutover;
            }
            if ($cutover->status !== InventoryCutoverStatus::Draft) {
                throw new DomainInvariantException('Only a draft inventory cutover can be validated.', 'status');
            }
            $batches = $cutover->batches()->lockForUpdate()->get();
            if ($batches->isEmpty() || ! $batches->contains('entity_type', 'OPENING_STATE')) {
                throw new DomainInvariantException('At least one opening-state batch is required.', 'batches');
            }
            $this->reconciler->assertStagedFactsAreTrustworthy($cutover);
            $openingBatches = $batches->where('entity_type', 'OPENING_STATE');
            $quantity = $openingBatches->reduce(fn (BigDecimal $sum, $batch): BigDecimal => $sum->plus($batch->source_quantity), BigDecimal::zero());
            $value = $openingBatches->reduce(fn (BigDecimal $sum, $batch): BigDecimal => $sum->plus($batch->source_value), BigDecimal::zero());
            $baseValue = $openingBatches->reduce(fn (BigDecimal $sum, $batch): BigDecimal => $sum->plus($batch->base_source_value), BigDecimal::zero());
            if (! $quantity->isEqualTo($cutover->control_quantity) || ! $value->isEqualTo($cutover->control_value) || ! $baseValue->isEqualTo($cutover->base_control_value)) {
                throw new DomainInvariantException('Source totals do not reconcile to the cutover control totals.', 'control_quantity');
            }
            $this->policyTransitions->record($cutover);
            foreach ($batches as $batch) {
                $batch->update(['status' => InventoryCutoverBatchStatus::Validated]);
            }
            $cutover->update(['status' => InventoryCutoverStatus::Validated, 'validated_by' => $actor->id, 'validated_at' => now()]);

            return $cutover->refresh()->load('batches');
        });
    }
}
