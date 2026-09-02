<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\Uom;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use App\Support\Status;
use App\Support\UomDimension;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCompany(int $companyId): void
    {
        $this->app->instance(CompanyContext::class, new class($companyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
    }

    private function createSecondCompany(): int
    {
        return DB::table('companies')->insertGetId([
            'name' => 'Other Company',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private ?Uom $defaultUom = null;

    private ?Category $defaultCategory = null;

    private function defaultUom(): Uom
    {
        return $this->defaultUom ??= Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1']);
    }

    private function defaultCategory(): Category
    {
        return $this->defaultCategory ??= Category::create([
            'code' => 'RAW', 'name' => 'Raw materials', 'default_valuation_method' => ValuationMethod::Avco,
        ]);
    }

    /**
     * A distinct uom, for tests asserting the relation resolves to the right row rather
     * than just any row.
     */
    private function makeUom(array $attributes = []): Uom
    {
        return Uom::create(array_merge(['code' => 'each', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1'], $attributes));
    }

    /**
     * A distinct category from defaultCategory(), for tests that need their own — e.g. one
     * carrying different GL accounts, without colliding on the ['company_id', 'code'] unique.
     * Carries a default_valuation_method so item creation resolves unless a test overrides it.
     */
    private function makeCategory(array $attributes = []): Category
    {
        return Category::create(array_merge(
            ['code' => 'OTHER', 'name' => 'Other', 'default_valuation_method' => ValuationMethod::Avco],
            $attributes,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseAttributes(array $overrides = []): array
    {
        return array_merge([
            'code' => 'PIG-001',
            'name' => 'Red Pigment',
            'type' => ItemType::Raw,
            'stock_uom_id' => $this->defaultUom()->id,
            'category_id' => $this->defaultCategory()->id,
        ], $overrides);
    }

    public function test_code_is_unique_per_company(): void
    {
        Item::create($this->baseAttributes());

        $this->expectException(QueryException::class);

        Item::create($this->baseAttributes(['code' => 'PIG-001']));
    }

    public function test_code_is_normalised_to_lowercase(): void
    {
        $item = Item::create($this->baseAttributes(['code' => 'PIG-001']));

        $this->assertSame('pig-001', $item->fresh()->code);
    }

    public function test_it_is_company_scoped(): void
    {
        Item::create($this->baseAttributes());

        $this->assertSame(1, Item::query()->first()->company_id);
    }

    public function test_category_id_cannot_point_at_another_companys_category(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCategory = $this->makeCategory();
        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);

        Item::create($this->baseAttributes([
            'category_id' => $otherCategory->id,
            'valuation_method' => ValuationMethod::Avco,
        ]));
    }

    public function test_stock_uom_id_cannot_point_at_another_companys_uom(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherUom = $this->makeUom();
        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);

        Item::create($this->baseAttributes(['stock_uom_id' => $otherUom->id]));
    }

    public function test_status_defaults_to_active_and_casts_to_the_enum(): void
    {
        $item = Item::create($this->baseAttributes());

        $this->assertSame(Status::Active, $item->fresh()->status);
    }

    public function test_type_and_tracking_cast_to_their_enums_with_tracking_defaulting_to_none(): void
    {
        $item = Item::create($this->baseAttributes(['type' => ItemType::Labour]));

        $this->assertSame(ItemType::Labour, $item->fresh()->type);
        $this->assertSame(ItemTracking::None, $item->fresh()->tracking);
    }

    public function test_the_stocked_purchasable_and_sellable_flags_default_to_false(): void
    {
        $item = Item::create($this->baseAttributes());

        $this->assertFalse($item->fresh()->is_stocked);
        $this->assertFalse($item->fresh()->is_purchasable);
        $this->assertFalse($item->fresh()->is_sellable);
    }

    public function test_custom_fields_cast_to_an_array_via_has_custom_fields(): void
    {
        $item = Item::create($this->baseAttributes(['custom_fields' => ['shade_code' => 'RAL 3020']]));

        $this->assertSame(['shade_code' => 'RAL 3020'], $item->fresh()->custom_fields);
    }

    public function test_category_and_stock_uom_relations_resolve(): void
    {
        $uom = $this->makeUom();
        $category = $this->makeCategory();
        $item = Item::create($this->baseAttributes(['stock_uom_id' => $uom->id, 'category_id' => $category->id]));

        $this->assertSame($uom->id, $item->stockUom->id);
        $this->assertSame($category->id, $item->category->id);
    }

    public function test_gl_resolution_prefers_the_items_own_account_over_its_category(): void
    {
        $categoryIncome = ChartOfAccount::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income]);
        $itemIncome = ChartOfAccount::create(['code' => '4010', 'name' => 'Paint Sales', 'type' => AccountType::Income]);

        $category = $this->makeCategory(['income_account_id' => $categoryIncome->id]);
        $item = Item::create($this->baseAttributes(['category_id' => $category->id, 'income_account_id' => $itemIncome->id]));

        $this->assertSame($itemIncome->id, $item->resolveIncomeAccountId());
    }

    public function test_gl_resolution_falls_through_to_the_category_when_the_item_leaves_it_unset(): void
    {
        $income = ChartOfAccount::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income]);
        $expense = ChartOfAccount::create(['code' => '5000', 'name' => 'COGS', 'type' => AccountType::Expense]);
        $inventory = ChartOfAccount::create(['code' => '1200', 'name' => 'Inventory', 'type' => AccountType::Asset]);

        $category = $this->makeCategory([
            'income_account_id' => $income->id, 'expense_account_id' => $expense->id, 'inventory_account_id' => $inventory->id,
        ]);
        $item = Item::create($this->baseAttributes(['category_id' => $category->id]));

        $this->assertSame($income->id, $item->resolveIncomeAccountId());
        $this->assertSame($expense->id, $item->resolveExpenseAccountId());
        $this->assertSame($inventory->id, $item->resolveInventoryAccountId());
    }

    public function test_gl_resolution_returns_null_when_neither_the_item_nor_its_category_chain_sets_it(): void
    {
        $item = Item::create($this->baseAttributes());

        $this->assertNull($item->resolveIncomeAccountId());
    }

    public function test_valuation_method_defaults_from_the_categorys_resolved_default(): void
    {
        $category = $this->makeCategory(['default_valuation_method' => ValuationMethod::Fifo]);
        $item = Item::create($this->baseAttributes(['category_id' => $category->id]));

        $this->assertSame(ValuationMethod::Fifo, $item->fresh()->valuation_method);
    }

    public function test_valuation_method_can_be_overridden_per_item(): void
    {
        $category = $this->makeCategory(['default_valuation_method' => ValuationMethod::Avco]);
        $item = Item::create($this->baseAttributes(['category_id' => $category->id, 'valuation_method' => ValuationMethod::Specific]));

        $this->assertSame(ValuationMethod::Specific, $item->fresh()->valuation_method);
    }

    public function test_valuation_method_is_stored_once_and_does_not_track_a_later_category_change(): void
    {
        $category = $this->makeCategory(['default_valuation_method' => ValuationMethod::Avco]);
        $item = Item::create($this->baseAttributes(['category_id' => $category->id]));

        $category->update(['default_valuation_method' => ValuationMethod::Fifo]);

        $this->assertSame(ValuationMethod::Avco, $item->fresh()->valuation_method);
    }

    public function test_creating_an_item_throws_when_neither_it_nor_its_category_chain_sets_a_valuation_method(): void
    {
        $category = Category::create(['code' => 'UNRESOLVED', 'name' => 'Unresolved']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no valuation_method given');

        Item::create($this->baseAttributes(['category_id' => $category->id]));
    }

    public function test_income_expense_and_inventory_account_ids_can_be_set_to_a_same_company_account(): void
    {
        $income = ChartOfAccount::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income]);
        $expense = ChartOfAccount::create(['code' => '5000', 'name' => 'COGS', 'type' => AccountType::Expense]);
        $inventory = ChartOfAccount::create(['code' => '1200', 'name' => 'Inventory', 'type' => AccountType::Asset]);

        $item = Item::create($this->baseAttributes([
            'income_account_id' => $income->id, 'expense_account_id' => $expense->id, 'inventory_account_id' => $inventory->id,
        ]));

        $this->assertSame($income->id, $item->fresh()->income_account_id);
        $this->assertSame($expense->id, $item->fresh()->expense_account_id);
        $this->assertSame($inventory->id, $item->fresh()->inventory_account_id);
    }

    public function test_income_expense_and_inventory_account_ids_can_remain_null(): void
    {
        $item = Item::create($this->baseAttributes());

        $this->assertNull($item->fresh()->income_account_id);
        $this->assertNull($item->fresh()->expense_account_id);
        $this->assertNull($item->fresh()->inventory_account_id);
    }

    /**
     * Item::create() assigns the column straight from an attribute array — the same shape
     * a raw FK write would take. There is no separate, more-guarded "picker" path, so this
     * also proves direct attribute assignment can't bypass the guard.
     */
    public function test_income_expense_and_inventory_account_ids_cannot_point_at_another_companys_account(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyAccount = ChartOfAccount::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income]);
        $this->actingAsCompany(1);

        foreach (['income_account_id', 'expense_account_id', 'inventory_account_id'] as $column) {
            try {
                Item::create($this->baseAttributes(['code' => 'PIG-'.$column, $column => $otherCompanyAccount->id]));
                $this->fail("{$column} accepted another company's account.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('does not resolve to a ChartOfAccount', $e->getMessage());
            }
        }
    }

    public function test_income_expense_and_inventory_account_ids_cannot_be_updated_to_another_companys_account(): void
    {
        $item = Item::create($this->baseAttributes());

        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyAccount = ChartOfAccount::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income]);
        $this->actingAsCompany(1);

        foreach (['income_account_id', 'expense_account_id', 'inventory_account_id'] as $column) {
            try {
                $item->fresh()->update([$column => $otherCompanyAccount->id]);
                $this->fail("{$column} accepted another company's account on update.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('does not resolve to a ChartOfAccount', $e->getMessage());
            }
        }
    }
}
