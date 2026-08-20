<?php

declare(strict_types=1);

namespace App\Modules\Parties\Models;

use App\Models\Model;
use App\Modules\Parties\Support\CustomerSiteRole;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

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

    protected static function booted(): void
    {
        static::saving(function (CustomerSite $site): void {
            if ($site->exists && ! $site->isDirty(['customer_account_id', 'party_site_id'])) {
                return;
            }

            $customerAccount = CustomerAccount::query()->whereKey($site->customer_account_id)->first();
            $partySite = PartySite::query()->whereKey($site->party_site_id)->first();

            if ($customerAccount === null || $partySite === null || $customerAccount->party_id !== $partySite->party_id) {
                throw new RuntimeException(
                    'CustomerSite must reference a customer account and party site belonging to the same party in the current company.'
                );
            }
        });
    }

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
