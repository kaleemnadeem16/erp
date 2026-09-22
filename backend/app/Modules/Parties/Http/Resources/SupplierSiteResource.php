<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Resources;

use App\Modules\Parties\Models\SupplierSite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A supplier's order-from/ship-from address — the role-specific join row plus
 * its shared PartySite, always nested together: a caller never needs the join
 * row without the address it points to. See docs/domain/parties.md "Shared sites".
 *
 * @mixin SupplierSite
 */
class SupplierSiteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SupplierSite $site */
        $site = $this->resource;

        return [
            'id' => $site->id,
            'supplier_account_id' => $site->supplier_account_id,
            'role' => $site->role->value,
            'is_default' => $site->is_default,
            'party_site' => new PartySiteResource($site->partySite),
            'created_at' => $site->created_at?->toIso8601String(),
            'updated_at' => $site->updated_at?->toIso8601String(),
        ];
    }
}
