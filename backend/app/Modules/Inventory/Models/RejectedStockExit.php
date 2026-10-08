<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The immutable record of rejected stock leaving for good — a supplier return or a scrap (ADR 0097). It is the authoritative history and the cap:
 * for a receipt line, what QA finally rejected less what has already exited. It never states a quality decision (that is the QA disposition) or a
 * financial settlement (that is a later AP/GL concern); it states which stock moved, why, and whether the outcome differed from the QA decision.
 *
 * @property int $goods_receipt_line_id
 * @property string $kind SUPPLIER_RETURN | SCRAP
 * @property int $stock_move_id
 * @property string $quantity
 * @property string $matching_qty
 * @property string $differing_qty
 * @property string|null $outcome_change_reason
 * @property string $reason
 */
class RejectedStockExit extends Model
{
    use BelongsToCompany;

    public const SUPPLIER_RETURN = 'SUPPLIER_RETURN';

    public const SCRAP = 'SCRAP';

    protected $table = 'rejected_stock_exits';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('A rejected-stock exit is immutable; correct it with a new, named transaction.'));
        static::deleting(fn () => throw new DomainInvariantException('A rejected-stock exit is immutable; correct it with a new, named transaction.'));
    }

    /** @return BelongsTo<StockMove, $this> */
    public function stockMove(): BelongsTo
    {
        return $this->belongsTo(StockMove::class);
    }

    /** The movement `source_type` each kind posts under (the valuation feed's accounting class, ADR 0097 §5). */
    public static function sourceType(string $kind): string
    {
        return $kind === self::SUPPLIER_RETURN ? 'SUPPLIER_RETURN' : 'SCRAP_WRITE_OFF';
    }
}
