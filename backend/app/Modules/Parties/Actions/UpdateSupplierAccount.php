<?php

declare(strict_types=1);

namespace App\Modules\Parties\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\AccountMappingAccess;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Support\AccountTermsAccess;
use App\Modules\Parties\Support\PartyWriteAccess;
use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

final class UpdateSupplierAccount
{
    public function __construct(private readonly PartyWriteAccess $access) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(SupplierAccount $supplier_account, array $attributes, User $actor): SupplierAccount
    {
        $this->access->require($actor, PermissionVocabulary::SUPPLIER_ACCOUNT_MANAGE, $supplier_account->company_id);

        AccountMappingAccess::requireWrite($actor, $attributes);
        AccountTermsAccess::requireWrite($actor, $attributes);

        return DB::transaction(function () use ($supplier_account, $attributes): SupplierAccount {
            if (isset($attributes['party_id'])) {
                Party::query()->whereKey($attributes['party_id'])->lockForUpdate()->firstOrFail();
            }

            $supplier_account->update($attributes);

            return $supplier_account->refresh();
        });
    }
}
