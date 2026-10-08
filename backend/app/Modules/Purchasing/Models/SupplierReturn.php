<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Supplier Return (ADR 0097). A draft records intent and moves nothing; posting calls the shared rejected-stock exit core and freezes the document (the database
 * enforces the freeze too). The expected credit amount, currency and reference are information only: this document creates no credit note, supplier balance change or journal.
 *
 * @property int $id
 * @property int $warehouse_id
 * @property string|null $number
 * @property string $status DRAFT | POSTED | CANCELLED
 * @property string $reason
 * @property int $created_by
 * @property int|null $posted_by
 * @property CarbonImmutable|null $posted_at
 * @property int|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property Carbon|null $created_at
 * @property int $supplier_account_id
 * @property string|null $supplier_reference
 * @property string|null $carrier_note
 * @property string|null $expected_credit_amount
 * @property int|null $expected_credit_currency_id
 * @property string|null $expected_credit_reference
 */
class SupplierReturn extends Model
{
    use BelongsToCompany;

    public const DRAFT = 'DRAFT';

    public const POSTED = 'POSTED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'supplier_returns';

    protected $guarded = ['id', 'status', 'number', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new DomainInvariantException('A return or scrap document is never deleted; cancel a draft instead.'));
    }

    protected function casts(): array
    {
        return ['posted_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'expected_credit_amount' => 'decimal:4'];
    }

    /** @return HasMany<SupplierReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierReturnLine::class, 'supplier_return_id')->orderBy('line_no');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }
}
