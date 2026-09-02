<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Modules\Purchasing\Support\DispositionOutcome;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $goods_receipt_line_id
 * @property int|null $source_disposition_id
 * @property DispositionOutcome $disposition
 * @property string $qty
 * @property string $reason
 * @property int $disposed_by
 * @property Carbon $disposed_at
 * @property int|null $resulting_stock_move_id
 * @property-read GoodsReceiptLine $goodsReceiptLine
 * @property-read QualityDisposition|null $sourceDisposition
 */
class QualityDisposition extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (QualityDisposition $disposition): void {
            if ($disposition->source_disposition_id !== null
                && ! self::query()->where('goods_receipt_line_id', $disposition->goods_receipt_line_id)
                    ->whereKey($disposition->source_disposition_id)->exists()) {
                throw new DomainInvariantException('source_disposition_id must belong to the same receipt line.', 'source_disposition_id');
            }
        });

        static::updating(function (): void {
            throw new DomainInvariantException('Posted quality dispositions are immutable.', 'id');
        });

        static::deleting(function (): void {
            throw new DomainInvariantException('Posted quality dispositions cannot be deleted.', 'id');
        });
    }

    /** @return array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    protected static function companyScopedForeignKeys(): array
    {
        return ['goods_receipt_line_id' => GoodsReceiptLine::class, 'disposed_by' => User::class];
    }

    protected function casts(): array
    {
        return [
            'disposition' => DispositionOutcome::class,
            'qty' => 'decimal:4',
            'disposed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<GoodsReceiptLine, $this> */
    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }

    /** @return BelongsTo<QualityDisposition, $this> */
    public function sourceDisposition(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_disposition_id');
    }

    /** @return HasMany<QualityDisposition, $this> */
    public function childDispositions(): HasMany
    {
        return $this->hasMany(self::class, 'source_disposition_id');
    }
}
