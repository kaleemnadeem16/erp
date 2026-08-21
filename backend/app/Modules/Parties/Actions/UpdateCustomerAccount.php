<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use Illuminate\Support\Facades\DB;

final class UpdateCustomerAccount
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(CustomerAccount $customer_account, array $attributes): CustomerAccount
    {
        return DB::transaction(function () use ($customer_account, $attributes): CustomerAccount {
            if (isset($attributes['party_id'])) {
                Party::query()->whereKey($attributes['party_id'])->lockForUpdate()->firstOrFail();
            }

            $customer_account->update($attributes);

            return $customer_account->refresh();
        });
    }
}
