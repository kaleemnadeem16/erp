<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Model;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RejectedStockExit;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a Supplier Return: the delivery line the rejected stock came from, the quantity, the reason the outcome differs from the QA decision (when it does) and,
 * for a grouped item, the declared physical portion. Posting links the line to its immutable exit record.
 *
 * @property int $id
 * @property int $line_no
 * @property int $item_id
 * @property int $location_id
 * @property int $goods_receipt_line_id
 * @property string $quantity
 * @property string|null $outcome_change_reason
 * @property array<int, array<string, mixed>>|null $package_portion
 * @property int|null $rejected_stock_exit_id
 */
class SupplierReturnLine extends Model
{
    use BelongsToCompany;

    protected $table = 'supplier_return_lines';

    protected $guarded = ['id', 'rejected_stock_exit_id'];

    /** Frozen lines show their item code and name, so load it with the line (archived items included). */
    protected $with = ['item'];

    protected function casts(): array
    {
        return ['package_portion' => 'array', 'quantity' => 'decimal:4'];
    }

    /** @return BelongsTo<GoodsReceiptLine, $this> */
    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<RejectedStockExit, $this> */
    public function rejectedStockExit(): BelongsTo
    {
        return $this->belongsTo(RejectedStockExit::class);
    }
}
