<?php

namespace App\Modules\Parties\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\HasCustomFields;
use App\Support\Status;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The shared trading-partner identity — see docs/domain/parties.md and
 * docs/decisions/0030-unified-party-model.md. Customer- and supplier-specific
 * configuration lives on CustomerAccount/SupplierAccount, not here: if a
 * value differs depending on whether we're buying from or selling to this
 * party, it doesn't belong on this model.
 *
 * @property string $code
 * @property string $name
 * @property string|null $registration_no
 * @property string|null $tax_id
 * @property string|null $website
 * @property array<string, string|int|float|bool>|null $custom_fields
 * @property Status $status
 */
class Party extends Model
{
    use BelongsToCompany;
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
     * @return HasMany<PartyContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(PartyContact::class);
    }

    /**
     * @return HasMany<PartySite, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(PartySite::class);
    }

    /**
     * @return HasOne<CustomerAccount, $this>
     */
    public function customerAccount(): HasOne
    {
        return $this->hasOne(CustomerAccount::class);
    }

    /**
     * @return HasOne<SupplierAccount, $this>
     */
    public function supplierAccount(): HasOne
    {
        return $this->hasOne(SupplierAccount::class);
    }
}
