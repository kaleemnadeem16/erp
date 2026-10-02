<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

final class UpdateCustomerAccount
{
    public function __construct(private readonly PartyWriteAccess $access) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(CustomerAccount $customer_account, array $attributes, User $actor): CustomerAccount
    {
        $this->access->require($actor, PermissionVocabulary::CUSTOMER_ACCOUNT_MANAGE, $customer_account->company_id);

        return DB::transaction(function () use ($customer_account, $attributes): CustomerAccount {
            if (isset($attributes['party_id'])) {
                Party::query()->whereKey($attributes['party_id'])->lockForUpdate()->firstOrFail();
            }

            $customer_account->update($attributes);

            return $customer_account->refresh();
        });
    }
}
