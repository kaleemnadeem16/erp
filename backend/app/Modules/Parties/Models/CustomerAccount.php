<?php

declare(strict_types=1);

namespace App\Modules\Parties\Models;

use App\Models\Currency;
use App\Models\Model;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use App\Support\Concerns\HasCustomFields;
use App\Support\Status;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The customer role hung off a shared Party — see docs/domain/parties.md and
 * docs/decisions/0030-unified-party-model.md. receivable_account_id is the
 * customer's control account (an ASSET); revenue comes from item/category
 * income_account_id, not from here — see parties.md "Receivable and payable are
 * control accounts, not income".
 *
 * @property int $party_id
 * @property string $code
 * @property string|null $credit_limit
 * @property int $payment_terms_days
 * @property int|null $tax_code_id
 * @property int $receivable_account_id
 * @property int|null $default_currency_id
 * @property array<string, mixed>|null $custom_fields
 * @property Status $status
 */
class CustomerAccount extends Model
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
            'credit_limit' => 'decimal:4',
            'status' => Status::class,
        ];
    }

    /**
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    protected static function companyScopedForeignKeys(): array
    {
        return ['party_id' => Party::class];
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
    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'receivable_account_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_id');
    }

    /**
     * @return HasMany<CustomerSite, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(CustomerSite::class);
    }
}
