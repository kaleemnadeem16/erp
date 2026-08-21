<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Modules\Parties\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;

final class ArchiveCustomerAccount
{
    public function __invoke(CustomerAccount $customer_account): void
    {
        DB::transaction(fn () => $customer_account->delete());
    }
}
