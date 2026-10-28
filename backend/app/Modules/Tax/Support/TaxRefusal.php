<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

use App\Exceptions\DomainInvariantException;

final class TaxRefusal extends DomainInvariantException
{
    public static function because(string $code, string $message): self
    {
        return new self($message, null, $code);
    }
}
