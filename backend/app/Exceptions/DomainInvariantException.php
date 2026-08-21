<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A model-level guard rejected a client-supplied relationship or required value
 * — a foreign key that doesn't resolve in the current company, a hierarchy that
 * would cycle, a required default nothing in a resolution chain provided. These
 * are reachable from ordinary API input (docs/architecture/multi-tenancy.md's
 * guard traits, Item's valuation_method requirement), so a plain RuntimeException
 * would otherwise surface as an uncaught 500 instead of the 422 api-conventions.md
 * requires for a business-rule violation. Mapped to one in bootstrap/app.php.
 *
 * Extends RuntimeException, not a fresh base, so every existing
 * expectException(RuntimeException::class) test keeps passing unchanged.
 */
class DomainInvariantException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
