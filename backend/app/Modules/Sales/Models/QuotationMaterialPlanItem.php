<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * @property int $id
 * @property int $item_id
 * @property string $standard_quantity
 * @property string $planned_quantity
 * @property string|null $effect
 * @property array<string, mixed>|null $mix
 *
 * Append-only evidence of a quotation Assembly line's Material Plan (ADR 0190). Written only while its revision is a draft and never updated; internal-cost figures in the
 * protected columns are never returned by any Resource. See the migration for the guarantees the database enforces.
 */
class QuotationMaterialPlanItem extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'quotation_material_plan_items';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['matched' => 'array', 'mix' => 'array'];
    }
}
