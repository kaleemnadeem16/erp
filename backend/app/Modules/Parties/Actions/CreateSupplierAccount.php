<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

final class CreateSupplierAccount
{
    public function __construct(private readonly PartyWriteAccess $access) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): SupplierAccount
    {
        $this->access->require($actor, PermissionVocabulary::SUPPLIER_ACCOUNT_MANAGE);

        return DB::transaction(function () use ($attributes): SupplierAccount {
            Party::query()->whereKey($attributes['party_id'])->lockForUpdate()->firstOrFail();
            $supplier_account = SupplierAccount::create($attributes);

            return $supplier_account->refresh();
        });
    }
}
