<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token plus `meta`: how and where this device signed in (`auth_methods`, `authenticated_at`, `ip`, `user_agent`). The
 * methods and time are what a later step-up rule ("recently authenticated with a second factor") needs, recorded from the start so no
 * data has to be reconstructed. Nothing here is a secret: the token itself is only ever stored hashed.
 *
 * @property array<string, mixed>|null $meta
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'meta'];
}
