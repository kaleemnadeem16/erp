<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $revision_id
 * @property int $line_id
 * @property int|null $supersedes_id
 * @property int $assembly_version_id
 * @property string $work_quantity
 * @property int $work_uom_id
 * @property array<string, mixed> $inputs
 * @property string $effect
 * @property string $routing_fingerprint
 * @property int $adopted_by
 *
 * Append-only evidence of a quotation Assembly line's Material Plan (ADR 0190). Written only while its revision is a draft and never updated; internal-cost figures in the
 * protected columns are never returned by any Resource. See the migration for the guarantees the database enforces.
 */
class QuotationMaterialPlan extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'quotation_material_plans';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['work_quantity' => 'decimal:4', 'inputs' => 'array', 'adopted_at' => 'datetime'];
    }

    /** @return HasMany<QuotationMaterialPlanItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationMaterialPlanItem::class, 'plan_id');
    }
}
