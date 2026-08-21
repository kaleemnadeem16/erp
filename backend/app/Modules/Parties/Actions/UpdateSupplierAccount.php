<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\SupplierAccount;
use Illuminate\Support\Facades\DB;

final class UpdateSupplierAccount
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(SupplierAccount $supplier_account, array $attributes): SupplierAccount
    {
        return DB::transaction(function () use ($supplier_account, $attributes): SupplierAccount {
            if (isset($attributes['party_id'])) {
                Party::query()->whereKey($attributes['party_id'])->lockForUpdate()->firstOrFail();
            }

            $supplier_account->update($attributes);

            return $supplier_account->refresh();
        });
    }
}
