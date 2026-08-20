<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Model;
use App\Models\Uom;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\HasCustomFields;
use App\Support\Status;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Everything the company buys, holds, makes, consumes or sells — see
 * docs/domain/items.md. resolve*AccountId() prefers the item's own account
 * column and falls through to its category's resolution
 * (Category::resolve*AccountId()) when unset, per "GL mapping" in that doc.
 *
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property ItemType $type
 * @property int $stock_uom_id
 * @property bool $is_stocked
 * @property bool $is_purchasable
 * @property bool $is_sellable
 * @property string $valuation_method
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
    use HasCustomFields;
    use SoftDeletes;

    protected $guarded = ['id'];

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
