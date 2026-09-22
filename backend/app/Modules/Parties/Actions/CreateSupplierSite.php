<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Modules\Parties\Models\PartySite;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Models\SupplierSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the shared PartySite (the address) and the SupplierSite join (the
 * supplier-role meaning) together, in one transaction — from the UI's side
 * this is "add an address for this supplier", one form, not two records; see
 * docs/domain/parties.md "Shared sites" for why they're two tables underneath.
 */
final class CreateSupplierSite
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(SupplierAccount $supplierAccount, array $attributes): SupplierSite
    {
        return DB::transaction(function () use ($supplierAccount, $attributes): SupplierSite {
            $role = $attributes['role'];
            $isDefault = (bool) ($attributes['is_default'] ?? false);

            $partySite = $supplierAccount->party->sites()->create([
                'code' => $this->uniqueCode($supplierAccount->party_id, $attributes['name']),
                'name' => $attributes['name'],
                'address_line1' => $attributes['address_line1'] ?? null,
                'address_line2' => $attributes['address_line2'] ?? null,
                'city' => $attributes['city'] ?? null,
                'state' => $attributes['state'] ?? null,
                'postal_code' => $attributes['postal_code'] ?? null,
                'country_code' => $attributes['country_code'] ?? null,
            ]);

            // Only one default per role — flip any existing default off rather
            // than allow two, the same way an address book keeps one default.
            if ($isDefault) {
                $supplierAccount->sites()->where('role', $role)->update(['is_default' => false]);
            }

            $site = $supplierAccount->sites()->create([
                'party_site_id' => $partySite->id,
                'role' => $role,
                'is_default' => $isDefault,
            ]);

            return $site->load('partySite');
        });
    }

    /**
     * `party_sites` is unique on (party_id, code); the form only asks for a
     * name, so a code is derived from it here and de-duplicated rather than
     * exposing an internal-identifier field the user has no reason to fill in.
     */
    private function uniqueCode(int $partyId, string $name): string
    {
        $base = strtoupper((string) Str::slug($name, '-'));
        $base = $base === '' ? 'SITE' : substr($base, 0, 40);

        $code = $base;
        $suffix = 1;
        while (
            PartySite::withTrashed()->where('party_id', $partyId)->where('code', $code)->exists()
        ) {
            $suffix++;
            $code = "{$base}-{$suffix}";
        }

        return $code;
    }
}
