<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Modules\Parties\Models\SupplierSite;
use Illuminate\Support\Facades\DB;

/**
 * Removes the supplier's *role* on this address, not the address itself —
 * the underlying PartySite is left alone since it may still be shared with
 * a customer role of the same party (see docs/domain/parties.md "Shared
 * sites"). Deleting the address itself, if ever needed, is a PartySite
 * operation, not this one.
 */
final class DeleteSupplierSite
{
    public function __invoke(SupplierSite $supplierSite): void
    {
        DB::transaction(fn () => $supplierSite->delete());
    }
}
