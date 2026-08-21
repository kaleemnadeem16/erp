<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use Illuminate\Support\Facades\DB;

final class CreateCustomerAccount
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): CustomerAccount
    {
        return DB::transaction(function () use ($attributes): CustomerAccount {
            Party::query()->whereKey($attributes['party_id'])->lockForUpdate()->firstOrFail();
            $customer_account = CustomerAccount::create($attributes);

            return $customer_account->refresh();
        });
    }
}
