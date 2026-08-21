<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Resources;

use App\Modules\Parties\Models\Party;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Party
 */
class PartyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Party $party */
        $party = $this->resource;

        return [
            'id' => $party->id,
            'code' => $party->code,
            'name' => $party->name,
            'registration_no' => $party->registration_no,
            'tax_id' => $party->tax_id,
            'website' => $party->website,
            'custom_fields' => $party->custom_fields,
            'status' => $party->status->value,
            'customer_account' => $this->whenLoaded('customerAccount', fn () => new CustomerAccountResource($party->customerAccount)),
            'supplier_account' => $this->whenLoaded('supplierAccount', fn () => new SupplierAccountResource($party->supplierAccount)),
            'created_at' => $party->created_at?->toIso8601String(),
            'updated_at' => $party->updated_at?->toIso8601String(),
        ];
    }
}
