<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Reconstructs historical counted evidence. Never enables tracking or supplies a valuation. */
final class ReplayPackageCheckpoint
{
    /** @return array<string,mixed> */
    public function __invoke(int $operationId, User $actor): array
    {
        if (! $actor->can('inventory.package-state.view') || (int) $actor->company_id !== app(CompanyContext::class)->id()) {
            throw new DomainInvariantException('Package-state view authority in the current company is required.');
        }

        return DB::transaction(function () use ($operationId): array {
            $operation = PackageOperation::query()->findOrFail($operationId);
            // Ordinary stock and checkpoint writers take this same stable scope lock.
            Location::query()->lockForUpdate()->findOrFail($operation->location_id);
            $item = Item::query()->findOrFail($operation->item_id);
            $states = PackageState::query()->where('operation_id', $operation->id)->orderBy('id')->get();
            $parts = PackageContribution::query()->whereIn('state_id', $states->pluck('id'))->orderBy('position')->orderBy('id')->get()->groupBy('state_id');
            $total = BigDecimal::zero();
            $loose = BigDecimal::zero();
            $vector = [];
            $composition = [];
            $integrity = $states->isNotEmpty();
            foreach ($states as $state) {
                $sum = BigDecimal::zero();
                $contributions = [];
                foreach ($parts->get($state->id, collect()) as $part) {
                    $qty = BigDecimal::of($part->quantity)->toScale(4);
                    $sum = $sum->plus($qty);
                    $vector[$part->stock_layer_id] = ($vector[$part->stock_layer_id] ?? BigDecimal::zero())->plus($qty);
                    $contributions[] = ['layer_id' => (int) $part->stock_layer_id, 'position' => (int) $part->position, 'quantity' => (string) $qty];
                }
                $quantity = BigDecimal::of($state->stock_quantity)->toScale(4);
                $integrity = $integrity && $quantity->isEqualTo($sum);
                $total = $total->plus($quantity);
                if ($state->form === 'OPEN_LOOSE') {
                    $loose = $loose->plus($quantity);
                }
                $composition[] = [
                    'state_id' => $state->id, 'form' => $state->form, 'availability_evidence' => $state->availability,
                    'packaging_id' => $state->item_packaging_id, 'packaging_code' => $state->packaging_code,
                    'packaging_name' => $state->packaging_name, 'sealed_count' => $state->sealed_count,
                    'content_quantity' => $state->content_quantity, 'quantity' => (string) $quantity,
                    'contributions' => $contributions,
                ];
            }
            $recordedVector = array_map(fn ($qty) => (string) $qty->toScale(4), $vector);
            ksort($recordedVector);
            $scope = ['item_id' => $operation->item_id, 'batch_id' => $operation->batch_id, 'serial_no' => null];
            $layers = StockLayer::query()->where($scope)->where('location_id', $operation->location_id)->where('qty_remaining', '>', 0)->orderBy('id')->get();
            $currentVector = $layers->mapWithKeys(fn ($layer) => [$layer->id => (string) BigDecimal::of($layer->qty_remaining)->toScale(4)])->all();
            $incoming = StockMove::query()->where($scope)->where('to_location_id', $operation->location_id)->sum('qty');
            $outgoing = StockMove::query()->where($scope)->where('from_location_id', $operation->location_id)->sum('qty');
            $currentQty = BigDecimal::of((string) $incoming)->minus((string) $outgoing)->toScale(4);
            $watermark = StockMove::query()->where($scope)
                ->where(fn ($q) => $q->where('to_location_id', $operation->location_id)->orWhere('from_location_id', $operation->location_id))->max('id');
            $latest = PackageOperation::query()->where([
                'item_id' => $operation->item_id, 'location_id' => $operation->location_id, 'batch_id' => $operation->batch_id,
            ])->where('evidence_kind', 'COUNTED')->max('id');
            $reasons = [];
            if (! $integrity) {
                $reasons[] = 'INCOMPLETE_EVIDENCE';
            }
            if ($operation->stock_move_watermark === null) {
                $reasons[] = 'UNKNOWN_MOVEMENT_WATERMARK';
            } elseif ((int) $operation->stock_move_watermark !== (int) $watermark) {
                $reasons[] = 'LATER_STOCK_MOVEMENT';
            }
            if ($operation->evidence_kind === 'COUNTED' && (int) $latest !== $operation->id) {
                $reasons[] = 'SUPERSEDED_CHECKPOINT';
            }
            if ($recordedVector !== $currentVector || ! $total->isEqualTo($currentQty)) {
                $reasons[] = 'STOCK_QUANTITIES_CHANGED';
            }
            if ($operation->evidence_kind !== 'COUNTED' || $item->tracking === ItemTracking::Serial || $item->valuation_method === ValuationMethod::Specific) {
                $reasons[] = 'UNSUPPORTED_EVIDENCE_SCOPE';
            }
            if (NegativeStockShortage::query()->where('item_id', $item->id)->where('qty_open', '>', 0)->exists()) {
                $reasons[] = 'OPEN_STOCK_SHORTAGE';
            }
            $receiptIds = $layers->where('source_type', 'GOODS_RECEIPT_LINE')->pluck('source_id');
            if (GoodsReceiptLine::query()->whereIn('id', $receiptIds)->whereHas('purchaseOrderLine', fn ($q) => $q->where('qa_required', true))->exists()) {
                $reasons[] = 'QA_ADAPTER_REQUIRED';
            }

            return [
                'operation_id' => $operation->id, 'evidence_kind' => $operation->evidence_kind,
                'source_receipt_line_id' => $operation->source_receipt_line_id,
                'tracking_active' => false, 'integrity_valid' => $integrity,
                'matches_current_stock' => $reasons === [], 'mismatch_reasons' => $reasons,
                'latest_checkpoint_id' => (int) $latest,
                'recorded_quantity' => (string) $total->toScale(4), 'current_quantity' => (string) $currentQty,
                'open_loose_quantity' => (string) $loose->toScale(4),
                'stock_move_watermark' => $operation->stock_move_watermark,
                'composition' => $composition,
            ];
        });
    }
}
