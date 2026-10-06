<?php

declare(strict_types=1);

namespace App\Support\Auth;

/** What a device token issued to one person may do and for how long. */
final readonly class DeviceTokenRules
{
    /** @param  list<string>  $abilities */
    public function __construct(public int $lifetimeMinutes, public array $abilities = ['*']) {}
}
