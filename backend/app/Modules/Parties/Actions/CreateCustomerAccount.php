<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

final class CreateCustomerAccount
{
    public function __construct(private readonly PartyWriteAccess $access) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): CustomerAccount
    {
        $this->access->require($actor, PermissionVocabulary::CUSTOMER_ACCOUNT_MANAGE);

        return DB::transaction(function () use ($attributes): CustomerAccount {
            Party::query()->whereKey($attributes['party_id'])->lockForUpdate()->firstOrFail();
            $customer_account = CustomerAccount::create($attributes);

            return $customer_account->refresh();
        });
    }
}
