<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Actions\Movements\StockIdentity;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\InventorySourceFact;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ValuationMethod;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Independent reconciliation boundary for the staged and committed opening
 * state. Mapping may reshape source data, but this class trusts only the
 * immutable InventorySourceFact result and requires the posted ledger to
 * reproduce it exactly by location, target AVCO pool and company total.
 */
final class InventoryCutoverReconciler
{
    public function __construct(private readonly StockIdentity $identity) {}

    public function assertStagedFactsAreTrustworthy(InventoryCutover $cutover): void
    {
        $facts = $this->facts($cutover);
        $serials = [];

        foreach ($facts as $fact) {
            $item = $fact->item;
            $location = $fact->location;
            $qty = BigDecimal::of($fact->qty);
            $amount = BigDecimal::of($fact->amount);
            $baseAmount = BigDecimal::of($fact->base_amount);

            if (! $item->is_stocked || $fact->uom_id !== $item->stock_uom_id) {
                throw new DomainInvariantException("Item {$item->code}'s opening quantity must use its stock UoM.", 'uom_id');
            }
            if (! $location->active || ! $location->allows_stock || ! $location->warehouse->active) {
                throw new DomainInvariantException('Every opening balance requires an active stock-holding location.', 'location_id');
            }
            if ($qty->isZero() && (! $amount->isZero() || ! $baseAmount->isZero())) {
                throw new DomainInvariantException('A zero opening quantity must carry zero value.', 'amount');
            }
            if (! $qty->isZero()) {
                $this->identity->validateReceiptTracking(
                    $item,
                    $qty->abs(),
                    $this->identity->normalisedBatchNo(['batch_no' => $fact->batch_no]),
                    $this->identity->normalisedSerialNo(['serial_no' => $fact->serial_no]),
                );
            }
            if ($qty->isNegative() && $item->tracking === ItemTracking::Serial) {
                throw new DomainInvariantException('Serial-tracked opening stock cannot be negative.', 'qty');
            }
            if ($fact->unit_cost !== null) {
                $extended = $qty->multipliedBy($fact->unit_cost)->toScale(4, RoundingMode::HALF_UP);
                if (! $extended->isEqualTo($amount)) {
                    throw new DomainInvariantException('Opening quantity × unit cost must equal its stated value.', 'amount');
                }
            }
            $expectedBase = $fact->fx_rate === null
                ? $amount
                : $amount->multipliedBy($fact->fx_rate)->toScale(4, RoundingMode::HALF_UP);
            if (! $expectedBase->isEqualTo($baseAmount)) {
                throw new DomainInvariantException('Opening value × FX rate must equal its stated base value.', 'base_amount');
            }

            if ($fact->serial_no !== null) {
                $serialKey = $item->id.'|'.$fact->serial_no;
                if (isset($serials[$serialKey])) {
                    throw new DomainInvariantException("Serial {$fact->serial_no} appears more than once in the opening state.", 'serial_no');
                }
                $serials[$serialKey] = true;
            }
        }
    }

    public function assertCommittedStateReconciles(InventoryCutover $cutover): void
    {
        $facts = $this->facts($cutover);
        $factIds = $facts->pluck('id');
        $exceptionIds = $cutover->negativeOpenings()->pluck('id');
        $moves = StockMove::query()->with('valuationEntries')
            ->where(function ($query) use ($factIds, $exceptionIds): void {
                $query->where(function ($opening) use ($factIds): void {
                    $opening->whereIn('source_type', ['INVENTORY_CUTOVER_OPENING', 'INVENTORY_CUTOVER_SYNTHETIC'])
                        ->whereIn('source_id', $factIds);
                });
                if ($exceptionIds->isNotEmpty()) {
                    $query->orWhere(function ($shortage) use ($exceptionIds): void {
                        $shortage->where('source_type', 'INVENTORY_CUTOVER_OPENING_SHORTAGE')
                            ->whereIn('source_id', $exceptionIds);
                    });
                }
            })->get();

        $expected = [];
        foreach ($facts as $fact) {
            $key = $fact->item_id.'|'.$fact->location_id;
            $expected[$key] ??= $this->zeroTotals();
            $expected[$key]['quantity'] = $expected[$key]['quantity']->plus($fact->qty);
            $expected[$key]['value'] = $expected[$key]['value']->plus($fact->amount);
            $expected[$key]['base_value'] = $expected[$key]['base_value']->plus($fact->base_amount);
        }

        $actual = [];
        foreach ($moves as $move) {
            $locationId = $move->movement_kind === 'RECEIPT' ? $move->to_location_id : $move->from_location_id;
            $key = $move->item_id.'|'.$locationId;
            $actual[$key] ??= $this->zeroTotals();
            $signedQty = BigDecimal::of($move->qty);
            if ($move->movement_kind === 'ISSUE') {
                $signedQty = $signedQty->negated();
            }
            $actual[$key]['quantity'] = $actual[$key]['quantity']->plus($signedQty);
            foreach ($move->valuationEntries as $entry) {
                $actual[$key]['value'] = $actual[$key]['value']->plus($entry->amount);
                $actual[$key]['base_value'] = $actual[$key]['base_value']->plus($entry->base_amount);
            }
        }

        $this->assertTotalsMatch($expected, $actual, 'Committed opening stock does not reconcile to staged facts by exact item/location.');
        $companyActual = array_reduce($actual, function (array $sum, array $row): array {
            foreach (array_keys($sum) as $field) {
                $sum[$field] = $sum[$field]->plus($row[$field]);
            }

            return $sum;
        }, $this->zeroTotals());
        $companyExpected = [
            'quantity' => BigDecimal::of($cutover->control_quantity),
            'value' => BigDecimal::of($cutover->control_value),
            'base_value' => BigDecimal::of($cutover->base_control_value),
        ];
        $this->assertTotalsMatch(['company' => $companyExpected], ['company' => $companyActual], 'Committed opening stock does not reconcile to company control totals.');

        $provenanceByPool = $cutover->avcoPoolProvenance()->with('targetAvcoPool')->get()->groupBy('target_avco_pool_id');
        $positiveAvcoItemIds = $facts->filter(fn (InventorySourceFact $fact): bool => BigDecimal::of($fact->qty)->isPositive()
            && $fact->item->valuation_method === ValuationMethod::Avco)->pluck('item_id')->unique();
        if ($positiveAvcoItemIds->diff($cutover->avcoPoolProvenance()->pluck('item_id')->unique())->isNotEmpty()) {
            throw new DomainInvariantException('Every positive AVCO opening state must have target-pool reconciliation provenance.', 'batches');
        }
        foreach ($provenanceByPool as $rows) {
            $pool = $rows->first()->targetAvcoPool;
            $quantity = $rows->reduce(fn (BigDecimal $sum, $row): BigDecimal => $sum->plus($row->opening_quantity), BigDecimal::zero());
            $baseValue = $rows->reduce(fn (BigDecimal $sum, $row): BigDecimal => $sum->plus($row->base_opening_value), BigDecimal::zero());
            if (! $quantity->isEqualTo($pool->qty_on_hand) || ! $baseValue->isEqualTo($pool->inventory_value)) {
                throw new DomainInvariantException('A target AVCO pool does not reconcile to its approved opening contributions.', 'batches');
            }
        }
    }

    /**
     * @return array{
     *     location_totals: array<int, array{item_code:string, warehouse_code:string, location_code:string, quantity:string, value:string, base_value:string}>,
     *     transformation_decisions: array<int, array{source_record_id:string, source_column:string, target_field:string, type:string, value:mixed, reason:string, approved_by:int, approved_at:string}>
     * }
     */
    public function preview(InventoryCutover $cutover): array
    {
        $facts = $this->facts($cutover);
        $locations = [];
        $decisions = [];
        foreach ($facts as $fact) {
            $key = $fact->item_id.'|'.$fact->location_id;
            $locations[$key] ??= [
                'item_code' => $fact->item->code,
                'warehouse_code' => $fact->location->warehouse->code,
                'location_code' => $fact->location->code,
                ...$this->zeroTotals(),
            ];
            $locations[$key]['quantity'] = $locations[$key]['quantity']->plus($fact->qty);
            $locations[$key]['value'] = $locations[$key]['value']->plus($fact->amount);
            $locations[$key]['base_value'] = $locations[$key]['base_value']->plus($fact->base_amount);
            foreach (($fact->source_payload['mapped']['_transformations'] ?? []) as $decision) {
                $decisions[] = [
                    'source_record_id' => $fact->source_record_id,
                    'source_column' => (string) $decision['source_column'],
                    'target_field' => (string) $decision['target_field'],
                    'type' => (string) $decision['type'],
                    'value' => $decision['value'],
                    'reason' => (string) $decision['reason'],
                    'approved_by' => (int) $decision['approved_by'],
                    'approved_at' => (string) $decision['approved_at'],
                ];
            }
        }

        $locationTotals = [];
        foreach ($locations as $row) {
            $locationTotals[] = [
                'item_code' => $row['item_code'],
                'warehouse_code' => $row['warehouse_code'],
                'location_code' => $row['location_code'],
                'quantity' => (string) $row['quantity'],
                'value' => (string) $row['value'],
                'base_value' => (string) $row['base_value'],
            ];
        }

        return [
            'location_totals' => $locationTotals,
            'transformation_decisions' => $decisions,
        ];
    }

    /** @return Collection<int, InventorySourceFact> */
    private function facts(InventoryCutover $cutover): Collection
    {
        return $cutover->sourceFacts()->with(['item', 'location.warehouse'])->orderBy('id')->get();
    }

    /** @return array{quantity:BigDecimal, value:BigDecimal, base_value:BigDecimal} */
    private function zeroTotals(): array
    {
        return ['quantity' => BigDecimal::zero(), 'value' => BigDecimal::zero(), 'base_value' => BigDecimal::zero()];
    }

    /** @param array<string, array{quantity:BigDecimal, value:BigDecimal, base_value:BigDecimal}> $expected
     * @param  array<string, array{quantity:BigDecimal, value:BigDecimal, base_value:BigDecimal}>  $actual
     */
    private function assertTotalsMatch(array $expected, array $actual, string $message): void
    {
        $expectedKeys = array_keys($expected);
        $actualKeys = array_keys($actual);
        sort($expectedKeys);
        sort($actualKeys);
        if ($expectedKeys !== $actualKeys) {
            throw new DomainInvariantException($message, 'batches');
        }
        foreach ($expected as $key => $expectedRow) {
            foreach (array_keys($expectedRow) as $field) {
                if (! $expectedRow[$field]->isEqualTo($actual[$key][$field])) {
                    throw new DomainInvariantException($message, 'batches');
                }
            }
        }
    }
}
