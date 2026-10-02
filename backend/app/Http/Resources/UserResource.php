<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'must_change_password' => (bool) $user->must_change_password,
            // Navigation may use this as a convenience, but every endpoint
            // still authorises server-side. Never expose another user's
            // grants when this resource is nested in audit/history data.
            'permissions' => $this->when(
                $request->user()?->is($user) === true,
                fn () => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            ),
        ];
    }
}
