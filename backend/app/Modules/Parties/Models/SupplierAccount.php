<?php

declare(strict_types=1);

namespace App\Modules\Parties\Models;

use App\Models\Currency;
use App\Models\Model;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use App\Support\Concerns\HasCustomFields;
use App\Support\Status;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The supplier role hung off a shared Party — see docs/domain/parties.md and
 * docs/decisions/0030-unified-party-model.md. lead_time_days is this supplier's
 * general default; App\Modules\Inventory's item_suppliers.lead_time_days
 * (Phase 2, ADR 0033) overrides it per item.
 *
 * @property int $party_id
 * @property string $code
 * @property int $payment_terms_days
 * @property int|null $lead_time_days
 * @property int $payable_account_id
 * @property int|null $default_currency_id
 * @property array<string, mixed>|null $custom_fields
 * @property Status $status
 */
class SupplierAccount extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;
    use HasCustomFields;
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
        ];
    }

    /**
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    protected static function companyScopedForeignKeys(): array
    {
        return [
            'party_id' => Party::class,
            'payable_account_id' => ChartOfAccount::class,
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'payable_account_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_id');
    }

    /**
     * @return HasMany<SupplierSite, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(SupplierSite::class);
    }

    /** @return HasMany<ItemSupplier, $this> */
    public function suppliedItems(): HasMany
    {
        return $this->hasMany(ItemSupplier::class);
    }
}
