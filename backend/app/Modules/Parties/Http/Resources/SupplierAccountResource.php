<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Resources;

use App\Modules\Parties\Models\SupplierAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupplierAccount
 */
class SupplierAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SupplierAccount $account */
        $account = $this->resource;

        return [
            'id' => $account->id,
            'party_id' => $account->party_id,
            'code' => $account->code,
            'payment_terms_days' => $account->payment_terms_days,
            'lead_time_days' => $account->lead_time_days,
            'payable_account_id' => $account->payable_account_id,
            'default_currency_id' => $account->default_currency_id,
            'custom_fields' => $account->custom_fields,
            'status' => $account->status->value,
            'party' => $this->whenLoaded('party', fn () => new PartyResource($account->party)),
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }
}
