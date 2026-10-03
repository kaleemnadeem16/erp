<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Model;
use App\Models\Uom;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Inventory\Actions\ResolvePackageTrackingPolicy;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\PackageTrackingMode;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Support\CompanyContext;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use App\Support\Concerns\HasCustomFields;
use App\Support\Status;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Everything the company buys, holds, makes, consumes or sells — see
 * docs/domain/items.md. resolve*AccountId() prefers the item's own account
 * column and falls through to its category's resolution
 * (Category::resolve*AccountId()) when unset, per "GL mapping" in that doc.
 * The three account columns are guarded against cross-company assignment —
 * see GuardsRelatedCompanyScope.
 *
 * valuation_method works differently: it's resolved once, in booted()'s
 * saving hook, from an explicit override or Category::
 * resolveDefaultValuationMethod(), and stored directly — not re-resolved on
 * every read the way the GL columns are, since a later change to the
 * category default must not silently move an already-created item. See
 * docs/domain/items.md "Valuation method" and
 * docs/decisions/0032-per-item-valuation-method.md. valuation_method,
 * avco_pool_scope, stock_uom_id and tracking are locked by the updating hook
 * once the item has a stock_moves row.
 *
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property ItemType $type
 * @property int $stock_uom_id
 * @property string|null $quantity_precision
 * @property bool $is_stocked
 * @property bool $is_purchasable
 * @property bool $is_sellable
 * @property ValuationMethod $valuation_method
 * @property AvcoPoolScope|null $avco_pool_scope
 * @property ItemTracking $tracking
 * @property int $category_id
 * @property int|null $income_account_id
 * @property int|null $expense_account_id
 * @property int|null $inventory_account_id
 * @property array<string, mixed>|null $custom_fields
 * @property Status $status
 */
class Item extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;
    use HasCustomFields;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (Item $item): void {
            if (! $item->exists) {
                $policy = app(ResolvePackageTrackingPolicy::class)(
                    $item->package_tracking_mode, $item->category_id,
                );
                $policy['mode']->requireImplemented();
                $item->package_tracking_mode = $policy['mode'];
                $item->package_tracking_source = $policy['source'];
                $item->package_tracking_source_category_id = $policy['category_id'];
            } else {
                $item->package_tracking_mode?->requireImplemented();
                if ($item->isDirty(['package_tracking_mode', 'package_tracking_source', 'package_tracking_source_category_id'])) {
                    throw new DomainInvariantException('Item package policy is pinned; governed transitions are not implemented.', 'package_tracking_mode');
                }
            }
            // Resolve valuation_method here (not in a separate creating hook): Eloquent
            // always fires saving before creating on insert, so a hook downstream that
            // branches on valuation_method must see it already resolved.
            $item->valuation_method ??= $item->category?->resolveDefaultValuationMethod();

            if ($item->valuation_method === null) {
                throw new DomainInvariantException(sprintf(
                    'Item cannot be created: no valuation_method given, and category %d sets no default_valuation_method anywhere in its ancestry.',
                    $item->category_id,
                ), field: 'valuation_method');
            }

            if ($item->valuation_method === ValuationMethod::Avco) {
                $item->avco_pool_scope ??= Company::query()->findOrFail(app(CompanyContext::class)->id())->default_avco_pool_scope;
                if ($item->avco_pool_scope === AvcoPoolScope::CompanyItemBatch && $item->tracking !== ItemTracking::Batch) {
                    throw new DomainInvariantException('COMPANY_ITEM_BATCH AVCO requires BATCH tracking.', 'avco_pool_scope');
                }
            } else {
                $item->avco_pool_scope = null;
            }
            if ($item->valuation_method === ValuationMethod::Specific && $item->tracking !== ItemTracking::Serial) {
                throw new DomainInvariantException('SPECIFIC valuation requires SERIAL tracking.', 'tracking');
            }
        });

        static::updating(function (Item $item): void {
            if ($item->isDirty('stock_uom_id') && ItemPackaging::query()->where('item_id', $item->id)->exists()) {
                throw new DomainInvariantException('Stock unit cannot change after package definitions exist.', 'stock_uom_id');
            }
            $locked = array_values(array_intersect(
                ['valuation_method', 'avco_pool_scope', 'stock_uom_id', 'tracking'],
                array_keys($item->getDirty()),
            ));
            if ($locked !== [] && StockMove::query()->where('item_id', $item->getKey())->exists()) {
                // Attributed to whichever locked field the caller actually
                // changed, so the message renders against that form field
                // rather than always against valuation_method. `tracking` is
                // locked alongside the other three: StockIdentity's
                // validate*Tracking()/findOnHandBatch() key batch/serial
                // identity off the item's *current* tracking mode, so
                // changing it after stock exists strands the pre-change
                // on-hand quantity — it can never acquire the identity a
                // later mode now requires.
                throw new DomainInvariantException(
                    'Valuation method, AVCO pool scope, stock UoM and tracking mode are immutable after the first stock movement.',
                    $locked[0],
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
            'category_id' => Category::class,
            'package_tracking_source_category_id' => Category::class,
            'stock_uom_id' => Uom::class,
            'income_account_id' => ChartOfAccount::class,
            'expense_account_id' => ChartOfAccount::class,
            'inventory_account_id' => ChartOfAccount::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'is_stocked' => 'bool',
            'is_purchasable' => 'bool',
            'is_sellable' => 'bool',
            'valuation_method' => ValuationMethod::class,
            'avco_pool_scope' => AvcoPoolScope::class,
            'tracking' => ItemTracking::class,
            'package_tracking_mode' => PackageTrackingMode::class,
            'quantity_precision' => 'decimal:9',
            'status' => Status::class,
        ];
    }

    /**
     * Postgres is case-sensitive, so PIG-001 and pig-001 would otherwise be
     * distinct rows despite the unique constraint — see docs/domain/items.md
     * "Codes and case sensitivity".
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtolower($value),
        );
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Uom, $this>
     */
    public function stockUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'stock_uom_id');
    }

    /** @return HasMany<ItemSupplier, $this> */
    public function suppliers(): HasMany
    {
        return $this->hasMany(ItemSupplier::class);
    }

    /** @return HasMany<ItemQuantityUnit, $this> */
    public function quantityUnits(): HasMany
    {
        return $this->hasMany(ItemQuantityUnit::class);
    }

    public function resolveIncomeAccountId(): ?int
    {
        return $this->income_account_id ?? $this->category->resolveIncomeAccountId();
    }

    public function resolveExpenseAccountId(): ?int
    {
        return $this->expense_account_id ?? $this->category->resolveExpenseAccountId();
    }

    public function resolveInventoryAccountId(): ?int
    {
        return $this->inventory_account_id ?? $this->category->resolveInventoryAccountId();
    }
}
