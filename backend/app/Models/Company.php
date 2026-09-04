<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Support\PeriodStatus;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\BackdatingHorizonMode;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Modules\Inventory\Support\NegativeStockAuthorisationMode;
use App\Modules\Inventory\Support\NegativeStockMode;
use App\Modules\Inventory\Support\PostingFloorSubtype;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The tenant-isolation boundary itself — see docs/architecture/multi-tenancy.md.
 * Single row (id 1) until multi-tenancy is enabled; App\Support\CompanyContext is
 * the seam that changes then, not this model. base_currency_id/fiscal_year_start_month
 * implement docs/decisions/0024-onboarding-base-currency-and-fiscal-year.md.
 *
 * @property string $name
 * @property int|null $base_currency_id
 * @property int $fiscal_year_start_month
 * @property InventoryLocationMode $inventory_location_mode
 * @property AvcoPoolScope $default_avco_pool_scope
 * @property NegativeStockMode $negative_stock_mode
 * @property NegativeStockAuthorisationMode $negative_stock_authorisation_mode
 * @property int $negative_stock_review_sla_hours
 * @property bool $negative_stock_allow_self_review
 * @property bool $negative_stock_sales_despatch_enabled
 * @property bool $negative_stock_material_consumption_enabled
 * @property bool $negative_stock_supplier_return_enabled
 * @property bool $negative_stock_scrap_writeoff_enabled
 * @property string $accounting_timezone
 * @property BackdatingHorizonMode $backdating_horizon_mode
 * @property PostingFloorSubtype|null $backdating_posting_floor_subtype
 * @property Carbon|null $backdating_posting_floor_fixed_date
 * @property int|null $backdating_posting_floor_rolling_days
 * @property bool $authorised_forward_recalculation_enabled
 * @property int $recalculation_review_sla_hours
 * @property int $recalculation_approval_ttl_hours
 */
class Company extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'inventory_location_mode' => InventoryLocationMode::class,
            'default_avco_pool_scope' => AvcoPoolScope::class,
            'negative_stock_mode' => NegativeStockMode::class,
            'negative_stock_authorisation_mode' => NegativeStockAuthorisationMode::class,
            'negative_stock_allow_self_review' => 'boolean',
            'negative_stock_sales_despatch_enabled' => 'boolean',
            'negative_stock_material_consumption_enabled' => 'boolean',
            'negative_stock_supplier_return_enabled' => 'boolean',
            'negative_stock_scrap_writeoff_enabled' => 'boolean',
            'backdating_horizon_mode' => BackdatingHorizonMode::class,
            'backdating_posting_floor_subtype' => PostingFloorSubtype::class,
            'backdating_posting_floor_fixed_date' => 'date',
            'authorised_forward_recalculation_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Company $company): void {
            if (! $company->isDirty('fiscal_year_start_month')) {
                return;
            }

            // ADR 0024's lock condition is the first closed period. Permissioned
            // close/reopen transitions remain Phase 6; this guard protects the
            // company setting once the persisted accounting state says CLOSED.
            if (AccountingPeriod::withoutGlobalScopes()
                ->where('company_id', $company->getKey())
                ->where('status', PeriodStatus::Closed->value)
                ->exists()) {
                throw new RuntimeException(
                    'Company fiscal_year_start_month is immutable after an accounting period is closed.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
