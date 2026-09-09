<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\Uom;
use App\Modules\Imports\Models\ImportBatchRow;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use App\Modules\Purchasing\Support\OverReceiptMode;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $company_id
 * @property int $purchase_order_id
 * @property int $line_number
 * @property int $item_id
 * @property string|null $item_code
 * @property string $description
 * @property string $ordered_qty
 * @property int $purchase_uom_id
 * @property string $ordered_stock_qty
 * @property int $stock_uom_id
 * @property string $uom_factor
 * @property string $unit_price
 * @property Carbon|null $expected_delivery_date
 * @property bool $qa_required
 * @property OverReceiptMode|null $over_receipt_mode
 * @property string|null $over_receipt_tolerance_percent
 * @property string $short_closed_stock_qty
 * @property int|null $import_batch_row_id
 * @property string|null $source_record_id
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Item $item
 * @property string|null $posted_received_stock_qty
 */
class PurchaseOrderLine extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrderLine $line): void {
            $order = PurchaseOrder::query()->find($line->purchase_order_id);
            if ($order === null || $order->status !== PurchaseOrderStatus::Draft) {
                throw new DomainInvariantException('Lines can only be added to a draft purchase order.', 'purchase_order_id');
            }
        });

        static::updating(function (PurchaseOrderLine $line): void {
            if ($line->purchaseOrder->status !== PurchaseOrderStatus::Draft
                && $line->getDirty() !== []
                && array_diff(array_keys($line->getDirty()), ['short_closed_stock_qty', 'updated_at']) !== []) {
                throw new DomainInvariantException('Issued purchase-order line snapshots are immutable.', 'status');
            }
        });
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['purchase_order_id' => PurchaseOrder::class, 'item_id' => Item::class, 'purchase_uom_id' => Uom::class, 'stock_uom_id' => Uom::class];
    }

    protected function casts(): array
    {
        return [
            'over_receipt_mode' => OverReceiptMode::class,
            'qa_required' => 'bool',
            'expected_delivery_date' => 'date',
            'ordered_qty' => 'decimal:4', 'ordered_stock_qty' => 'decimal:4',
            'uom_factor' => 'decimal:9', 'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:4', 'tax_amount' => 'decimal:4', 'line_amount' => 'decimal:4',
            'base_unit_price' => 'decimal:4', 'base_discount_amount' => 'decimal:4',
            'base_tax_amount' => 'decimal:4', 'base_line_amount' => 'decimal:4',
            'over_receipt_tolerance_percent' => 'decimal:4', 'short_closed_stock_qty' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Uom, $this> */
    public function purchaseUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'purchase_uom_id');
    }

    /** @return BelongsTo<Uom, $this> */
    public function stockUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'stock_uom_id');
    }

    /** @return BelongsTo<ImportBatchRow, $this> */
    public function importBatchRow(): BelongsTo
    {
        return $this->belongsTo(ImportBatchRow::class);
    }

    /** @return HasMany<GoodsReceiptLine, $this> */
    public function receiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    /** @param Builder<PurchaseOrderLine> $query */
    public function scopeWithReceiptProgress(Builder $query): void
    {
        $query->withSum([
            'receiptLines as posted_received_stock_qty' => fn (Builder $receiptLines) => $receiptLines
                ->whereHas('goodsReceipt', fn (Builder $receipts) => $receipts
                    ->where('status', GoodsReceiptStatus::Posted->value)),
        ], 'stock_qty');
    }
}
