<?php

declare(strict_types=1);

namespace App\Modules\Parties\Models;

use App\Models\Model;
use App\Modules\Parties\Support\SupplierSiteRole;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * Attaches a role (order-from/ship-from) to a shared PartySite for the supplier
 * role — see docs/domain/parties.md "Shared sites". Scoped through
 * supplierAccount — see docs/architecture/multi-tenancy.md "Child tables scope
 * through their parent".
 *
 * @property int $supplier_account_id
 * @property int $party_site_id
 * @property SupplierSiteRole $role
 * @property bool $is_default
 */
class SupplierSite extends Model
{
    use BelongsToCompanyThroughParent;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (SupplierSite $site): void {
            if ($site->exists && ! $site->isDirty(['supplier_account_id', 'party_site_id'])) {
                return;
            }

            $supplierAccount = SupplierAccount::query()->whereKey($site->supplier_account_id)->first();
            $partySite = PartySite::query()->whereKey($site->party_site_id)->first();

            if ($supplierAccount === null || $partySite === null || $supplierAccount->party_id !== $partySite->party_id) {
                throw new RuntimeException(
                    'SupplierSite must reference a supplier account and party site belonging to the same party in the current company.'
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
            'role' => SupplierSiteRole::class,
            'is_default' => 'bool',
        ];
    }

    /**
     * @return BelongsTo<SupplierAccount, $this>
     */
    public function supplierAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierAccount::class);
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
        return 'supplierAccount';
    }
}
