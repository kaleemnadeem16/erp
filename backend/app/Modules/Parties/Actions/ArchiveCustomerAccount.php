<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

final class ArchiveCustomerAccount
{
    public function __construct(private readonly PartyWriteAccess $access) {}

    public function __invoke(CustomerAccount $customer_account, User $actor): void
    {
        $this->access->require($actor, PermissionVocabulary::CUSTOMER_ACCOUNT_MANAGE, $customer_account->company_id);

        DB::transaction(fn () => $customer_account->delete());
    }
}
