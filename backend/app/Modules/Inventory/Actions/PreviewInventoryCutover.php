<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\InventoryCutover;
use Brick\Math\BigDecimal;

final class PreviewInventoryCutover
{
    public function __construct(private readonly InventoryCutoverReconciler $reconciler) {}

    /** @return array<string, mixed> */
    public function __invoke(InventoryCutover $cutover): array
    {
        $cutover->loadMissing('batches');
        $quantity = $cutover->batches->reduce(fn (BigDecimal $sum, $batch): BigDecimal => $sum->plus($batch->source_quantity), BigDecimal::zero());
        $value = $cutover->batches->reduce(fn (BigDecimal $sum, $batch): BigDecimal => $sum->plus($batch->source_value), BigDecimal::zero());
        $baseValue = $cutover->batches->reduce(fn (BigDecimal $sum, $batch): BigDecimal => $sum->plus($batch->base_source_value), BigDecimal::zero());

        return [
            'status' => $cutover->status->value,
            'batch_count' => $cutover->batches->count(),
            'source_record_count' => $cutover->batches->sum('source_record_count'),
            'accepted_record_count' => $cutover->batches->sum('accepted_record_count'),
            'rejected_record_count' => $cutover->batches->sum('rejected_record_count'),
            'source_quantity' => (string) $quantity,
            'source_value' => (string) $value,
            'base_source_value' => (string) $baseValue,
            'control_quantity' => $cutover->control_quantity,
            'control_value' => $cutover->control_value,
            'base_control_value' => $cutover->base_control_value,
            ...$this->reconciler->preview($cutover),
        ];
    }
}
