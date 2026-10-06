<?php

declare(strict_types=1);

namespace App\Modules\Administration\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * A resource owned by a unit, today a warehouse. A resource belongs to at most one unit; one that belongs to none is visible only to
 * unrestricted people (ADR 0089).
 *
 * @property int $unit_id
 * @property string $resource_type
 * @property int $resource_id
 */
class OrganisationUnitResource extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
}
