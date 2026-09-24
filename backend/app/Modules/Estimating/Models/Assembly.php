<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Models\Model;
use App\Models\Uom;
use App\Modules\Estimating\Support\AssemblyStatus;
use App\Modules\Estimating\Support\AssemblyVersionStatus;
use App\Modules\Estimating\Support\MarginType;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A sellable unit of work, priced per work_uom_id, no output item
 * (docs/decisions/0008-assembly-abstraction.md). Unlike Formulation's
 * output_item_id/customer_id, assembly_group_id is a mutable
 * classification, not identity — expected to change over time, so no
 * repoint guard blocks it.
 *
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $work_uom_id
 * @property int|null $assembly_group_id
 * @property MarginType $margin_type
 * @property string $margin_pct
 * @property AssemblyStatus $status
 * @property-read Uom $workUom
 * @property-read AssemblyGroup|null $assemblyGroup
 */
class Assembly extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    /**
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    protected static function companyScopedForeignKeys(): array
    {
        return [
            'work_uom_id' => Uom::class,
            'assembly_group_id' => AssemblyGroup::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'margin_type' => MarginType::class,
            'margin_pct' => 'decimal:4',
            'status' => AssemblyStatus::class,
        ];
    }

    /** @return BelongsTo<Uom, $this> */
    public function workUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'work_uom_id');
    }

    /** @return BelongsTo<AssemblyGroup, $this> */
    public function assemblyGroup(): BelongsTo
    {
        return $this->belongsTo(AssemblyGroup::class);
    }

    /** @return HasMany<AssemblyVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AssemblyVersion::class)->orderByDesc('version_no');
    }

    /** @return HasOne<AssemblyVersion, $this> */
    public function activeVersion(): HasOne
    {
        return $this->hasOne(AssemblyVersion::class)
            ->where('status', AssemblyVersionStatus::Active);
    }
}
