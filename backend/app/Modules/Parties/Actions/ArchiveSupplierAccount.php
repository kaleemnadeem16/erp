<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Modules\Parties\Models\SupplierAccount;
use Illuminate\Support\Facades\DB;

final class ArchiveSupplierAccount
{
    public function __invoke(SupplierAccount $supplier_account): void
    {
        DB::transaction(fn () => $supplier_account->delete());
    }
}
