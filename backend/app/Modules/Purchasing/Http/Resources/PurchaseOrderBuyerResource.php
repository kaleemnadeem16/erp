<?php

namespace App\Modules\Purchasing\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member who may be named buyer: the identifier and the name, nothing else. No email, status, roles, permissions, units or scope limits — this is not a member directory.
 *
 * @mixin User
 */
class PurchaseOrderBuyerResource extends JsonResource
{
    /** @return array{id: int, name: string} */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
