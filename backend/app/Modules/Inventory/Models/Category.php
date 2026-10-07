<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Model;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Inventory\Support\PackageTrackingMode;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsCompanyScopedHierarchy;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hierarchical grouping for items, carrying GL-account and valuation-method
 * defaults that an item inherits when it leaves its own columns unset — see
 * docs/domain/items.md "GL mapping" and "Valuation method". resolve*() walks
 * up parent() to the nearest ancestor with that column set; a chain that
 * resolves to nothing returns null rather than falling back to any default,
 * per that doc — the account resolvers stay that way, but
 * resolveDefaultValuationMethod() is only ever consulted by Item at creation
 * time, which does enforce a final answer (see Item::booted()). The three
 * account columns are guarded against cross-company assignment the same way
 * parent_id is — see GuardsRelatedCompanyScope.
 *
 * @property string $code
 * @property PackageTrackingMode|null $default_package_tracking_mode
 * @property string $name
 * @property int|null $parent_id
 * @property ValuationMethod|null $default_valuation_method
 * @property int|null $income_account_id
 * @property int|null $expense_account_id
 * @property int|null $inventory_account_id
 */
class Category extends Model
{
    use BelongsToCompany;
    use GuardsCompanyScopedHierarchy;
    use GuardsRelatedCompanyScope;

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            $category->default_package_tracking_mode?->requireImplemented();
        });
    }

    protected $guarded = ['id'];

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
            'default_valuation_method' => ValuationMethod::class,
            'default_package_tracking_mode' => PackageTrackingMode::class,
        ];
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

    public function resolveDefaultValuationMethod(): ?ValuationMethod
    {
        for ($category = $this; $category !== null; $category = $category->parent) {
            if ($category->default_valuation_method !== null) {
                return $category->default_valuation_method;
            }
        }

        return null;
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
}
