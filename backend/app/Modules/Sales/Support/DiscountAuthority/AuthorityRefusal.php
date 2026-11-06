<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Exceptions\DomainInvariantException;

final class AuthorityRefusal
{
    public static function make(string $code, string $message, string $field = 'authority'): DomainInvariantException
    {
        return new DomainInvariantException($message, $field, $code);
    }
}
