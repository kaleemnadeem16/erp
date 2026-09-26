<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\Uom;
use App\Modules\Estimating\Support\FormulationStatus;
use App\Modules\Estimating\Support\FormulationVersionStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\CustomerAccount;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A recipe: the inputs required to produce one base unit of output_item_id.
 * The recipe content itself lives on FormulationVersion, never here — see
 * docs/domain/formulations.md. `customer_id` NULL is the company default;
 * a non-null value is that customer's own override, resolved ahead of the
 * default by ResolveFormulation.
 *
 * @property int $output_item_id
 * @property string $code
 * @property string $name
 * @property string $output_qty
 * @property int $output_uom_id
 * @property int|null $customer_id
 * @property FormulationStatus $status
 * @property-read Item $outputItem
 * @property-read Uom $outputUom
 * @property-read CustomerAccount|null $customer
 */
class Formulation extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (Formulation $formulation): void {
            if ($formulation->isDirty(['output_item_id', 'customer_id'])) {
                throw new DomainInvariantException(
                    'A formulation cannot be repointed to a different output item or customer; archive it and create another.',
                    $formulation->isDirty('output_item_id') ? 'output_item_id' : 'customer_id',
                );
            }
        });
    }

    /**
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    protected static function companyScopedForeignKeys(): array
    {
        return [
            'output_item_id' => Item::class,
            'output_uom_id' => Uom::class,
            'customer_id' => CustomerAccount::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['output_qty' => 'decimal:6', 'status' => FormulationStatus::class];
    }

    /** @return BelongsTo<Item, $this> */
    public function outputItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'output_item_id');
    }

    /** @return BelongsTo<Uom, $this> */
    public function outputUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'output_uom_id');
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    /** @return HasMany<FormulationVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FormulationVersion::class)->orderByDesc('version_no');
    }

    /** @return HasOne<FormulationVersion, $this> */
    public function activeVersion(): HasOne
    {
        return $this->hasOne(FormulationVersion::class)
            ->where('status', FormulationVersionStatus::Active);
    }
}
