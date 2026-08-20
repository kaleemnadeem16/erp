<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Model;
use App\Models\Uom;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use App\Support\Concerns\HasCustomFields;
use App\Support\Status;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * Everything the company buys, holds, makes, consumes or sells — see
 * docs/domain/items.md. resolve*AccountId() prefers the item's own account
 * column and falls through to its category's resolution
 * (Category::resolve*AccountId()) when unset, per "GL mapping" in that doc.
 * The three account columns are guarded against cross-company assignment —
 * see GuardsRelatedCompanyScope.
 *
 * valuation_method works differently: it's resolved once, in booted()'s
 * creating hook, from an explicit override or Category::
 * resolveDefaultValuationMethod(), and stored directly — not re-resolved on
 * every read the way the GL columns are, since a later change to the
 * category default must not silently move an already-created item. See
 * docs/domain/items.md "Valuation method" and
 * docs/decisions/0032-per-item-valuation-method.md. Locking it once the item
 * has inventory transactions is a Phase 2 concern (no stock_moves table
 * exists yet to check against) — deliberately not implemented here.
 *
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property ItemType $type
 * @property int $stock_uom_id
 * @property bool $is_stocked
 * @property bool $is_purchasable
 * @property bool $is_sellable
 * @property ValuationMethod $valuation_method
 * @property ItemTracking $tracking
 * @property int $category_id
 * @property int|null $default_supplier_id
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
        static::creating(function (Item $item): void {
            $item->valuation_method ??= $item->category?->resolveDefaultValuationMethod();

            if ($item->valuation_method === null) {
                throw new RuntimeException(sprintf(
                    'Item cannot be created: no valuation_method given, and category %d sets no default_valuation_method anywhere in its ancestry.',
                    $item->category_id,
                ));
            }
        });
    }

    /**
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    protected static function companyScopedForeignKeys(): array
    {
        return [
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
            'tracking' => ItemTracking::class,
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
