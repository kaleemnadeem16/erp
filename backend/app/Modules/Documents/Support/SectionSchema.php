<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * One owner-defined block of a projection. A repeating section is a list of rows of the owner's own columns; Documents defines no universal line schema.
 */
final readonly class SectionSchema
{
    /** @param array<string, FieldSpec> $fields */
    public function __construct(public array $fields, public bool $repeating = false, public bool $required = true) {}
}
