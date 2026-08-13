<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;

/**
 * Backs App\Support\DefaultNumberingScheme — one row per (company, doc_type),
 * advanced under a row lock so numbers are gapless under concurrent load. See
 * docs/architecture/invariants.md#8-gapless-document-numbering.
 *
 * @property string $doc_type
 * @property string|null $prefix
 * @property int $next_number
 * @property int $padding
 */
class Sequence extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
}
