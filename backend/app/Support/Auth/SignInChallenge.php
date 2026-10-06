<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * A reason a correct password is not yet enough to sign in: "this person must also give a one-time code", "this device must be
 * confirmed". `type` names what the client must do next; `data` carries what it needs to do it. Nothing registers a requirement yet,
 * so no challenge exists today; the response shape is provisional until two-factor is researched and decided (Q37).
 */
final readonly class SignInChallenge
{
    /** @param  array<string, mixed>  $data */
    public function __construct(public string $type, public string $message, public array $data = []) {}
}
