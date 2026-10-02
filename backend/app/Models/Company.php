<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\BackdatingHorizonMode;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Modules\Inventory\Support\NegativeStockAuthorisationMode;
use App\Modules\Inventory\Support\NegativeStockMode;
use App\Modules\Inventory\Support\PostingFloorSubtype;
use App\Support\CompanyLocks\CompanyLocks;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property string $timezone IANA time zone of the company's books and business day (ADR 0081)
 * @property string|null $code stable short code, used in document numbers
 * @property string|null $legal_name
 * @property string|null $registration_number
 * @property string|null $tax_number
 * @property string|null $sales_tax_number
 * @property string|null $country_code ISO 3166-1 alpha-2
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property BackdatingHorizonMode $backdating_horizon_mode
 * @property PostingFloorSubtype|null $backdating_posting_floor_subtype
 * @property Carbon|null $backdating_posting_floor_fixed_date
 * @property int|null $backdating_posting_floor_rolling_days
 * @property bool $authorised_forward_recalculation_enabled
 * @property int $recalculation_review_sla_hours
 * @property int $recalculation_approval_ttl_hours
 * @property string|null $recalculation_high_risk_threshold_base_amount
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
            'recalculation_high_risk_threshold_base_amount' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Company $company): void {
            // The lifecycle rules of ADR 0024, enforced here so no caller — API, import or console — can bypass them. The same
            // reasons are shown to people on the Company screen (see CompanyLocks).
            $locks = app(CompanyLocks::class);
            if ($company->isDirty('fiscal_year_start_month') && ($reason = $locks->fiscalYearStartMonth($company)) !== null) {
                throw new DomainInvariantException($reason, 'fiscal_year_start_month');
            }
            if ($company->isDirty('base_currency_id') && $company->getOriginal('base_currency_id') !== null && ($reason = $locks->baseCurrency($company)) !== null) {
                throw new DomainInvariantException($reason, 'base_currency_id');
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
