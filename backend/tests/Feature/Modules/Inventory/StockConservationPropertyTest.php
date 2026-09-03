<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\DecideNegativeStockOverride;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RequestNegativeStockOverride;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\NegativeStockApprovalDecision;
use App\Modules\Inventory\Support\ValuationMethod;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Property test: any sequence of receipts, issues, authorised shortages,
 * transfers and reversals — accepted or rejected — must leave the valuation
 * feed, pool state, physical layers and open shortages in exact agreement.
 * Seeded, so a failure replays deterministically; quantities are integers
 * and costs two-decimal so every product is exact at the 4dp money scale
 * and the invariants can demand equality rather than tolerance.
 *
 * Deliberately excludes serial/SPECIFIC stock and batch identities — their
 * fixed one-unit shapes add little to conservation coverage. QA gating is
 * likewise out of scope here (direct receipts carry no QA source).
 */
class StockConservationPropertyTest extends StockIssueTest
{
    public function test_random_movement_sequences_conserve_value_and_quantity(): void
    {
        [$order, $avcoItem, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $avcoItem->update(['avco_pool_scope' => AvcoPoolScope::WarehouseItem]);
        $fifoItem = $avcoItem->replicate();
        $fifoItem->code = 'fuzz-fifo';
        $fifoItem->valuation_method = ValuationMethod::Fifo;
        $fifoItem->avco_pool_scope = null;
        $fifoItem->save();
        $locations = [$this->locationId(), $this->otherLocationId('f1'), $this->otherLocationId('f2')];
        Company::query()->findOrFail(1)->update([
            'negative_stock_mode' => 'AUTHORISED_OVERRIDE', 'negative_stock_authorisation_mode' => 'PRIOR_APPROVAL',
        ]);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.negative-stock.request');
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.negative-stock.approve');
        $sourceId = 9000;
        $receiptSources = [];

        foreach ([20260903, 424242] as $seed) {
            mt_srand($seed);
            for ($op = 0; $op < 45; $op++) {
                $item = mt_rand(0, 1) === 0 ? $avcoItem : $fifoItem;
                $locationId = $locations[mt_rand(0, 2)];
                $qty = mt_rand(1, 9).'.0000';
                $roll = mt_rand(1, 100);
                try {
                    if ($roll <= 35) {
                        $cost = number_format(mt_rand(500, 2000) / 100, 4, '.', '');
                        $this->directReceipt($item->id, $uom->id, $locationId, $order->currency_id, $qty, $cost, ++$sourceId);
                        $receiptSources[] = $sourceId;
                    } elseif ($roll <= 65) {
                        $this->issueWithOverrideFallback($item, $uom->id, $locationId, $qty, ++$sourceId, $requester, $approver);
                    } elseif ($roll <= 90) {
                        $destinationId = $locations[mt_rand(0, 2)];
                        if ($destinationId !== $locationId) {
                            app(PostInventoryMovement::class)->transfer($this->transferInput($item->id, $uom->id, $locationId, $destinationId, $qty, ++$sourceId));
                        }
                    } elseif ($receiptSources !== []) {
                        $target = $receiptSources[mt_rand(0, count($receiptSources) - 1)];
                        app(PostInventoryMovement::class)->reverseReceipt('DIRECT_RECEIPT', $target, 'FUZZ_REVERSAL', ++$sourceId, now());
                    }
                } catch (DomainInvariantException) {
                    // A rejected movement is a valid outcome; the invariants
                    // must hold regardless — including after the rejection's
                    // mid-flight transaction rollback.
                }
                $this->assertMovementInvariants($avcoItem, $fifoItem, $seed, $op);
            }
        }
    }

    /**
     * Issues normally; when exact-location stock is short, walks the full
     * ADR 0037 path instead — snapshot the genuine shortage, obtain an
     * independent approval and retry with the override.
     */
    private function issueWithOverrideFallback(Item $item, int $uomId, int $locationId, string $qty, int $sourceId, User $requester, User $approver): void
    {
        try {
            app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $uomId, $locationId, $qty, $sourceId));

            return;
        } catch (DomainInvariantException) {
        }
        $available = (string) StockLayer::query()->where('item_id', $item->id)->where('location_id', $locationId)
            ->whereNull('batch_id')->whereNull('serial_no')->sum('qty_remaining');
        if (BigDecimal::of($available === '' ? '0' : $available)->isGreaterThanOrEqualTo(BigDecimal::of($qty))) {
            return; // blocked for a non-shortage reason (e.g. no defensible provisional cost)
        }
        $request = app(RequestNegativeStockOverride::class)([
            'item_id' => $item->id, 'location_id' => $locationId, 'movement_class' => 'MATERIAL_CONSUMPTION',
            'requested_qty' => $qty, 'available_qty' => $available === '' ? '0' : $available,
            'source_type' => 'TEST_ISSUE', 'source_id' => $sourceId,
            'reason_code' => 'OPERATIONAL_EMERGENCY', 'explanation' => 'Seeded conservation fuzz shortage',
        ], $requester);
        app(DecideNegativeStockOverride::class)($request, NegativeStockApprovalDecision::Approved, 'Fuzz approved', $approver);
        app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uomId, $locationId, $qty, $sourceId),
            ['override_request_id' => $request->id],
        ));
    }

    private function assertMovementInvariants(Item $avcoItem, Item $fifoItem, int $seed, int $op): void
    {
        $at = "seed {$seed} op {$op}";
        $this->assertSame(0, StockLayer::query()->where('qty_remaining', '<', 0)->count(), "A stock layer went negative ({$at}).");
        $this->assertSame(0, AvcoPool::query()->where('qty_on_hand', '>', 0)->where('status', 'FROZEN')->count(), "A positive pool stayed frozen ({$at}).");

        foreach ([$avcoItem, $fifoItem] as $item) {
            $entryValue = $this->decimal(DB::table('stock_valuation_entries')
                ->join('stock_moves', 'stock_moves.id', '=', 'stock_valuation_entries.stock_move_id')
                ->where('stock_moves.item_id', $item->id)->sum('stock_valuation_entries.base_amount'));
            if ($item->valuation_method === ValuationMethod::Avco) {
                $poolValue = $this->decimal(AvcoPool::query()->where('item_id', $item->id)->sum('inventory_value'));
                $this->assertTrue($entryValue->isEqualTo($poolValue), "AVCO pool value {$poolValue} diverged from the valuation feed {$entryValue} ({$at}).");
                $poolQty = $this->decimal(AvcoPool::query()->where('item_id', $item->id)->sum('qty_on_hand'));
                $physicalQty = $this->decimal(StockLayer::query()->where('item_id', $item->id)->sum('qty_remaining'));
                $shortageQty = $this->decimal(NegativeStockShortage::query()->where('item_id', $item->id)
                    ->whereIn('status', ['OPEN', 'PARTIALLY_SETTLED'])->sum('qty_open'));
                $this->assertTrue($poolQty->isEqualTo($physicalQty->minus($shortageQty)), "AVCO pool quantity {$poolQty} diverged from physical {$physicalQty} minus open shortage {$shortageQty} ({$at}).");
            } else {
                $layerValue = $this->decimal(StockLayer::query()->where('item_id', $item->id)
                    ->selectRaw('COALESCE(SUM(qty_remaining * base_unit_cost), 0) AS total')->value('total'));
                $shortageValue = $this->decimal(NegativeStockShortage::query()->where('item_id', $item->id)
                    ->whereIn('status', ['OPEN', 'PARTIALLY_SETTLED'])
                    ->selectRaw('COALESCE(SUM(qty_open * provisional_unit_cost), 0) AS total')->value('total'));
                $this->assertTrue($entryValue->isEqualTo($layerValue->minus($shortageValue)), "FIFO layer value {$layerValue} minus open shortage {$shortageValue} diverged from the valuation feed {$entryValue} ({$at}).");
            }
        }
    }

    private function decimal(mixed $value): BigDecimal
    {
        $value = (string) ($value ?? '0');

        return BigDecimal::of($value === '' ? '0' : $value);
    }
}
