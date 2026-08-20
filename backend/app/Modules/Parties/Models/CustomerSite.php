<?php

declare(strict_types=1);

namespace App\Modules\Parties\Models;

use App\Models\Model;
use App\Modules\Parties\Support\CustomerSiteRole;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Attaches a role (bill-to/ship-to) to a shared PartySite for the customer role
 * — see docs/domain/parties.md "Shared sites". Scoped through customerAccount —
 * see docs/architecture/multi-tenancy.md "Child tables scope through their parent".
 *
 * @property int $customer_account_id
 * @property int $party_site_id
 * @property CustomerSiteRole $role
 * @property bool $is_default
 */
class CustomerSite extends Model
{
    use BelongsToCompanyThroughParent;
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => CustomerSiteRole::class,
            'is_default' => 'bool',
        ];
    }

    /**
     * @return BelongsTo<CustomerAccount, $this>
     */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    /**
     * @return BelongsTo<PartySite, $this>
     */
    public function partySite(): BelongsTo
    {
        return $this->belongsTo(PartySite::class);
    }

    protected static function companyScopeRelation(): string
    {
        return 'customerAccount';
    }
}
