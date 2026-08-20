<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A hierarchical grouping for items, carrying GL-account defaults that an item
 * inherits when it leaves its own account columns unset — see
 * docs/domain/items.md "GL mapping". resolve*AccountId() walks up parent() to
 * the nearest ancestor with that column set; a chain that resolves to nothing
 * returns null rather than falling back to any default, per that doc.
 *
 * @property string $code
 * @property string $name
 * @property int|null $parent_id
 * @property int|null $income_account_id
 * @property int|null $expense_account_id
 * @property int|null $inventory_account_id
 */
class Category extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            $category->guardParent();
        });
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function resolveIncomeAccountId(): ?int
    {
        return $this->resolveAccountId('income_account_id');
    }

    public function resolveExpenseAccountId(): ?int
    {
        return $this->resolveAccountId('expense_account_id');
    }

    public function resolveInventoryAccountId(): ?int
    {
        return $this->resolveAccountId('inventory_account_id');
    }

    private function resolveAccountId(string $column): ?int
    {
        for ($category = $this; $category !== null; $category = $category->parent) {
            if ($category->{$column} !== null) {
                return $category->{$column};
            }
        }

        return null;
    }

    /**
     * A raw parent_id foreign key can't stop two things on its own: pointing at
     * another company's category (Category carries its own company_id, unlike
     * BelongsToCompanyThroughParent's child tables, but nothing about a plain FK
     * checks the parent is in-scope) or reparenting a category under its own
     * descendant, which would turn the GL-resolution walk above into an infinite
     * loop — see docs/domain/items.md "Category cycles".
     */
    private function guardParent(): void
    {
        if (! $this->isDirty('parent_id') || $this->parent_id === null) {
            return;
        }

        if (! static::query()->whereKey($this->parent_id)->exists()) {
            throw new RuntimeException(sprintf(
                'Category cannot be saved: parent_id %d does not resolve to a category in the current company.',
                $this->parent_id,
            ));
        }

        $ancestorId = $this->parent_id;

        while ($ancestorId !== null) {
            if ($ancestorId === $this->id) {
                throw new RuntimeException(sprintf(
                    'Category %d cannot be reparented under %d: that would create a cycle.',
                    $this->id,
                    $this->parent_id,
                ));
            }

            $ancestorId = static::query()->whereKey($ancestorId)->value('parent_id');
        }
    }
}
