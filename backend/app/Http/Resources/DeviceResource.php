<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PersonalAccessToken */
class DeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PersonalAccessToken $token */
        $token = $this->resource;
        $current = $request->user()?->currentAccessToken();

        return [
            'id' => $token->id,
            'name' => $token->name,
            'created_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'is_current' => (bool) ($current instanceof PersonalAccessToken && $current->is($token)),
            'auth_methods' => array_values((array) ($token->meta['auth_methods'] ?? [])),
            'user_agent' => $token->meta['user_agent'] ?? null,
        ];
    }
}
