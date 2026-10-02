<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Parties\Models\SupplierSite;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
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
    public function __construct(private readonly PartyWriteAccess $access) {}

    public function __invoke(SupplierSite $supplierSite, User $actor): void
    {
        $this->access->require($actor, PermissionVocabulary::SUPPLIER_SITE_MANAGE, $supplierSite->supplierAccount->company_id);

        DB::transaction(fn () => $supplierSite->delete());
    }
}
