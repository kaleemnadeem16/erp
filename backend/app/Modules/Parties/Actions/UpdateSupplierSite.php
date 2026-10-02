<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Parties\Models\SupplierSite;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

/**
 * Splits one PATCH across the two tables a "site" is made of: address fields
 * land on the shared PartySite, role/default on the SupplierSite join — see
 * CreateSupplierSite's docblock for why they're presented as one thing.
 */
final class UpdateSupplierSite
{
    public function __construct(private readonly PartyWriteAccess $access) {}

    private const PARTY_SITE_FIELDS = ['name', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code'];

    /** @param array<string, mixed> $attributes */
    public function __invoke(SupplierSite $supplierSite, array $attributes, User $actor): SupplierSite
    {
        $this->access->require($actor, PermissionVocabulary::SUPPLIER_SITE_MANAGE, $supplierSite->supplierAccount->company_id);

        return DB::transaction(function () use ($supplierSite, $attributes): SupplierSite {
            $partySiteAttributes = array_intersect_key($attributes, array_flip(self::PARTY_SITE_FIELDS));
            if ($partySiteAttributes !== []) {
                $supplierSite->partySite->update($partySiteAttributes);
            }

            if (array_key_exists('is_default', $attributes) && $attributes['is_default']) {
                $role = $attributes['role'] ?? $supplierSite->role->value;
                $supplierSite->supplierAccount->sites()
                    ->where('role', $role)
                    ->whereKeyNot($supplierSite->id)
                    ->update(['is_default' => false]);
            }

            $joinAttributes = array_intersect_key($attributes, array_flip(['role', 'is_default']));
            if ($joinAttributes !== []) {
                $supplierSite->update($joinAttributes);
            }

            return $supplierSite->refresh()->load('partySite');
        });
    }
}
