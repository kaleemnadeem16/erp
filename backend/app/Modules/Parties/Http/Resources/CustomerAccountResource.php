<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Resources;

use App\Modules\Accounting\Support\AccountMappingAccess;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Support\AccountTermsAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerAccount
 */
class CustomerAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerAccount $account */
        $account = $this->resource;

        return [
            'id' => $account->id,
            'party_id' => $account->party_id,
            'code' => $account->code,
            'credit_limit' => $this->when(AccountTermsAccess::canViewCredit($request->user()), fn () => $account->credit_limit),
            'payment_terms_days' => $account->payment_terms_days,
            'tax_code_id' => $account->tax_code_id,
            'receivable_account_id' => $this->when(AccountMappingAccess::canView($request->user()), fn () => $account->receivable_account_id),
            'default_currency_id' => $account->default_currency_id,
            /** @var array<string, string|int|float|bool>|null */
            'custom_fields' => (object) ($account->custom_fields ?? []),
            'status' => $account->status->value,
            'party' => $this->whenLoaded('party', fn () => new PartyResource($account->party)),
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }
}
