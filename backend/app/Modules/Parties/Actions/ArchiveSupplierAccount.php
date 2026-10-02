<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

final class ArchiveSupplierAccount
{
    public function __construct(private readonly PartyWriteAccess $access) {}

    public function __invoke(SupplierAccount $supplier_account, User $actor): void
    {
        $this->access->require($actor, PermissionVocabulary::SUPPLIER_ACCOUNT_MANAGE, $supplier_account->company_id);

        DB::transaction(fn () => $supplier_account->delete());
    }
}
