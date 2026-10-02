<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

/** A variable a document type can supply, and whether its source is frozen once the document is numbered (ADR 0079 §12). */
final class VariableSupply
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $frozenAtNumbering = true,
    ) {}
}
