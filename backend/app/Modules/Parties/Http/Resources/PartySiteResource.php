<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Resources;

use App\Modules\Parties\Models\PartySite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The shared address record — see docs/domain/parties.md "Shared sites".
 * Nested inside SupplierSiteResource/CustomerSiteResource, which add the
 * role-specific meaning (order-from/ship-from, bill-to/ship-to, default).
 *
 * @mixin PartySite
 */
class PartySiteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PartySite $site */
        $site = $this->resource;

        return [
            'id' => $site->id,
            'party_id' => $site->party_id,
            'code' => $site->code,
            'name' => $site->name,
            'address_line1' => $site->address_line1,
            'address_line2' => $site->address_line2,
            'city' => $site->city,
            'state' => $site->state,
            'postal_code' => $site->postal_code,
            'country_code' => $site->country_code,
            'status' => $site->status->value,
        ];
    }
}
