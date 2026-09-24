<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Modules\Estimating\Support\AssemblyVersionStatus;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One assembly revision. Mirrors FormulationVersion exactly — DRAFT is a
 * scratch pad, ACTIVE is the one currently-costed version, SUPERSEDED is
 * historical and kept forever. See docs/domain/assemblies.md "Versioning".
 * Scoped through assembly, not its own company_id.
 *
 * @property int $assembly_id
 * @property int $version_no
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property AssemblyVersionStatus $status
 * @property string|null $notes
 * @property-read Assembly $assembly
 */
class AssemblyVersion extends Model
{
    use BelongsToCompanyThroughParent;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssemblyVersionStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** @return BelongsTo<Assembly, $this> */
    public function assembly(): BelongsTo
    {
        return $this->belongsTo(Assembly::class);
    }

    /** @return HasMany<AssemblyComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(AssemblyComponent::class)->orderBy('sequence');
    }

    protected static function companyScopeRelation(): string
    {
        return 'assembly';
    }
}
