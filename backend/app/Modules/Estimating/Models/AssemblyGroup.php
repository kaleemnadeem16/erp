<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A flat, optional, company-defined classification of assemblies for shared
 * overhead-policy configuration — see docs/domain/assemblies.md "Overhead
 * allocation" and docs/decisions/0049-assembly-group-overhead-scope.md.
 * Deliberately not a tree (no parent_id): no validated requirement for
 * multi-level grouping exists yet.
 *
 * @property string $code
 * @property string $name
 */
class AssemblyGroup extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    /** @return HasMany<Assembly, $this> */
    public function assemblies(): HasMany
    {
        return $this->hasMany(Assembly::class);
    }
}
