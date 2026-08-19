<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Archived, not deleted — see docs/domain/items.md#archiving-not-deleting and
 * docs/domain/parties.md. Shared across entities that use this exact
 * two-state lifecycle; a table needing more states (e.g. assembly_versions'
 * DRAFT | ACTIVE | SUPERSEDED) defines its own enum instead of stretching
 * this one.
 */
enum Status: string
{
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';
}
