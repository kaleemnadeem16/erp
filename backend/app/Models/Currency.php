<?php

namespace App\Models;

/**
 * Global, not company-scoped — docs/architecture/multi-tenancy.md rule 1 excludes
 * currencies alongside countries and system settings. No BelongsToCompany here.
 *
 * @property string $code
 * @property string $name
 * @property string $symbol
 * @property int $decimal_places
 */
class Currency extends Model
{
    protected $guarded = ['id'];
}
