<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\Model;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Models\SupplierSite;
use App\Modules\Purchasing\Support\PurchaseOrderFxPolicy;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string|null $number
 * @property int $supplier_account_id
 * @property int|null $supplier_site_id
 * @property PurchaseOrderStatus $status
 * @property int $currency_id
 * @property PurchaseOrderFxPolicy $fx_policy
 * @property string $fx_rate
 * @property string $fx_rate_type
 * @property string $fx_rate_source
 * @property Carbon $fx_rate_date
 * @property Carbon $order_date
 * @property Carbon|null $expected_delivery_date
 * @property string|null $notes
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $inventory_cutover_id
 * @property int|null $import_batch_id
 * @property string|null $source_system
 * @property string|null $source_record_id
 * @property-read SupplierAccount $supplierAccount
 * @property-read SupplierSite|null $supplierSite
 * @property-read Currency $currency
 */
class PurchaseOrder extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (PurchaseOrder $purchaseOrder): void {
            if ($purchaseOrder->getOriginal('status') !== PurchaseOrderStatus::Draft
                && $purchaseOrder->isDirty(['supplier_account_id', 'supplier_site_id', 'currency_id', 'fx_policy', 'fx_rate', 'fx_rate_date', 'fx_rate_type', 'fx_rate_source', 'order_date', 'expected_delivery_date', 'inventory_cutover_id', 'import_batch_id', 'source_system', 'source_record_id'])) {
                throw new DomainInvariantException('Issued purchase-order commercial data is immutable.', 'status');
            }
        });

        static::saving(function (PurchaseOrder $purchaseOrder): void {
            if (! $purchaseOrder->isDirty(['supplier_account_id', 'supplier_site_id'])
                || $purchaseOrder->supplier_site_id === null) {
                return;
            }

            $site = SupplierSite::query()->find($purchaseOrder->supplier_site_id);
            if ($site === null || $site->supplier_account_id !== $purchaseOrder->supplier_account_id) {
                throw new DomainInvariantException(
                    'A supplier site must belong to the selected supplier.',
                    'supplier_site_id',
                );
            }
        });
    }

    protected static function companyScopedForeignKeys(): array
    {
        return [
            'supplier_account_id' => SupplierAccount::class, 'supplier_site_id' => SupplierSite::class,
            'inventory_cutover_id' => InventoryCutover::class, 'import_batch_id' => ImportBatch::class,
        ];
    }

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'fx_policy' => PurchaseOrderFxPolicy::class,
            'fx_rate' => 'decimal:9',
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'fx_rate_date' => 'date',
            'issued_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_number');
    }

    /** @return BelongsTo<SupplierAccount, $this> */
    public function supplierAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierAccount::class);
    }

    /** @return BelongsTo<SupplierSite, $this> */
    public function supplierSite(): BelongsTo
    {
        return $this->belongsTo(SupplierSite::class);
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @return BelongsTo<InventoryCutover, $this> */
    public function inventoryCutover(): BelongsTo
    {
        return $this->belongsTo(InventoryCutover::class);
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
