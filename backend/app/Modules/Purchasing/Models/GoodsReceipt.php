<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $location_id
 * @property GoodsReceiptStatus $status
 * @property CarbonImmutable $physical_arrival_at
 * @property CarbonImmutable $recorded_at
 * @property Carbon $posting_date
 * @property CarbonImmutable|null $posted_at
 * @property CarbonImmutable|null $reversed_at
 * @property int|null $reversed_by
 */
class GoodsReceipt extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (GoodsReceipt $receipt): void {
            if ($receipt->getOriginal('status') !== GoodsReceiptStatus::Draft
                && array_diff(array_keys($receipt->getDirty()), ['status', 'reversed_at', 'reversed_by', 'correction_reason', 'updated_at']) !== []) {
                throw new DomainInvariantException('Posted goods-receipt facts are immutable.', 'status');
            }
        });
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['supplier_account_id' => SupplierAccount::class, 'reversal_of_id' => self::class, 'reversed_by' => User::class];
    }

    protected function casts(): array
    {
        return [
            'status' => GoodsReceiptStatus::class,
            'physical_arrival_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime',
            'posting_date' => 'date', 'posted_at' => 'immutable_datetime', 'reversed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<GoodsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    /** @return BelongsTo<SupplierAccount, $this> */
    public function supplierAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierAccount::class);
    }
}
