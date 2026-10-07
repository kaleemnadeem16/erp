<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\GoodsReceiptPackageObservation;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;

final class RecordReceiptComposition
{
    /**
     * Counts remain untrusted until the runtime integer check below.
     *
     * @param  list<array{packaging_id?:int|null,count?:mixed,quantity?:string}>  $composition
     */
    public function __invoke(GoodsReceiptLine $line, array $composition, string $reason, User $actor): void
    {
        if (! $actor->can('purchasing.goods-receipt.create') || (int) $actor->company_id !== app(CompanyContext::class)->id()) {
            throw new DomainInvariantException('Receipt creation authority in the current company is required.');
        }
        if (trim($reason) === '' || $composition === []) {
            throw new DomainInvariantException('Observed composition and a reason are required.');
        }
        DB::transaction(function () use ($line, $composition, $reason, $actor): void {
            $line = GoodsReceiptLine::query()->findOrFail($line->id);
            $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($line->goods_receipt_id);
            $line = GoodsReceiptLine::query()->lockForUpdate()->findOrFail($line->id);
            if ($receipt->status !== GoodsReceiptStatus::Draft) {
                throw new DomainInvariantException('Only draft receipt composition can be recorded.');
            }
            if (Item::query()->findOrFail($line->item_id)->tracking === ItemTracking::Serial) {
                throw new DomainInvariantException('Serial receipt composition is not supported yet.');
            }
            $rows = [];
            $total = BigDecimal::zero();
            foreach ($composition as $row) {
                $pack = isset($row['packaging_id']) ? ItemPackaging::query()->where('item_id', $line->item_id)->findOrFail($row['packaging_id']) : null;
                if ($pack !== null) {
                    if (! isset($row['count']) || ! is_int($row['count']) || $row['count'] < 1 || isset($row['quantity'])) {
                        throw new DomainInvariantException('Sealed receipt evidence requires whole positive counts.');
                    }
                    $content = $this->quantity($pack->stock_quantity);
                    $qty = $content->multipliedBy($row['count']);
                } else {
                    if (isset($row['count']) || ! isset($row['quantity'])) {
                        throw new DomainInvariantException('Loose receipt evidence requires quantity without a sealed count.');
                    }
                    $content = null;
                    $qty = $this->quantity($row['quantity']);
                }
                $total = $total->plus($qty);
                $rows[] = [
                    'goods_receipt_line_id' => $line->id, 'item_packaging_id' => $pack?->id,
                    'form' => $pack === null ? 'OPEN_LOOSE' : 'SEALED', 'sealed_count' => $row['count'] ?? null,
                    'content_quantity' => $content === null ? null : (string) $content,
                    'stock_quantity' => (string) $qty, 'observed_by' => $actor->id,
                    'reason' => trim($reason), 'observed_at' => now(),
                ];
            }
            if (! $total->isEqualTo($line->stock_qty)) {
                throw new DomainInvariantException('Observed contents must equal the receipt stock quantity.');
            }
            GoodsReceiptPackageObservation::query()->where('goods_receipt_line_id', $line->id)->delete();
            foreach ($rows as $row) {
                GoodsReceiptPackageObservation::query()->create($row);
            }
        });
    }

    private function quantity(string $input): BigDecimal
    {
        try {
            $qty = BigDecimal::of($input)->toScale(4);
        } catch (MathException|\InvalidArgumentException) {
            throw new DomainInvariantException('Observed quantity must be exact at stock precision.');
        }
        if ($qty->isLessThanOrEqualTo(0)) {
            throw new DomainInvariantException('Observed quantity must be positive.');
        }

        return $qty;
    }
}
