<?php

namespace Tests\Feature\Modules\Inventory;

use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CategoryTest extends TestCase
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

    /**
     * categories.company_id has a real FK to companies, so a second company must
     * actually exist. Its id can't be assumed to be 2 — Postgres sequences aren't
     * transactional, so RefreshDatabase's per-test rollback doesn't reset them.
     */
    private function createSecondCompany(): int
    {
        return DB::table('companies')->insertGetId([
            'name' => 'Other Company',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_code_is_unique_per_company(): void
    {
        Category::create(['code' => 'RAW', 'name' => 'Raw materials']);

        $this->expectException(QueryException::class);

        Category::create(['code' => 'RAW', 'name' => 'Duplicate']);
    }

    public function test_it_is_company_scoped(): void
    {
        Category::create(['code' => 'RAW', 'name' => 'Raw materials']);

        $this->assertSame(1, Category::query()->first()->company_id);
    }

    public function test_parent_and_children_relations_resolve(): void
    {
        $parent = Category::create(['code' => 'RAW', 'name' => 'Raw materials']);
        $child = Category::create(['code' => 'PIGMENTS', 'name' => 'Pigments', 'parent_id' => $parent->id]);

        $this->assertSame($parent->id, $child->parent->id);
        $this->assertSame($child->id, $parent->children->sole()->id);
    }

    public function test_gl_resolution_walks_up_to_the_nearest_ancestor_with_a_value_set(): void
    {
        $grandparent = Category::create([
            'code' => 'RAW', 'name' => 'Raw materials',
            'income_account_id' => 100, 'expense_account_id' => 200, 'inventory_account_id' => 300,
        ]);
        $parent = Category::create(['code' => 'PIGMENTS', 'name' => 'Pigments', 'parent_id' => $grandparent->id]);
        $child = Category::create(['code' => 'REDS', 'name' => 'Reds', 'parent_id' => $parent->id]);

        $this->assertSame(100, $child->resolveIncomeAccountId());
        $this->assertSame(200, $child->resolveExpenseAccountId());
        $this->assertSame(300, $child->resolveInventoryAccountId());
    }

    public function test_gl_resolution_prefers_the_nearer_ancestor(): void
    {
        $grandparent = Category::create(['code' => 'RAW', 'name' => 'Raw materials', 'income_account_id' => 100]);
        $parent = Category::create([
            'code' => 'PIGMENTS', 'name' => 'Pigments',
            'parent_id' => $grandparent->id, 'income_account_id' => 150,
        ]);
        $child = Category::create(['code' => 'REDS', 'name' => 'Reds', 'parent_id' => $parent->id]);

        $this->assertSame(150, $child->resolveIncomeAccountId());
    }

    public function test_gl_resolution_returns_null_when_nothing_in_the_chain_sets_it(): void
    {
        $parent = Category::create(['code' => 'RAW', 'name' => 'Raw materials']);
        $child = Category::create(['code' => 'PIGMENTS', 'name' => 'Pigments', 'parent_id' => $parent->id]);

        $this->assertNull($child->resolveIncomeAccountId());
    }

    public function test_default_valuation_method_casts_to_the_enum(): void
    {
        $category = Category::create(['code' => 'RAW', 'name' => 'Raw materials', 'default_valuation_method' => ValuationMethod::Avco]);

        $this->assertSame(ValuationMethod::Avco, $category->fresh()->default_valuation_method);
    }

    public function test_default_valuation_method_walks_up_to_the_nearest_ancestor_with_a_value_set(): void
    {
        $grandparent = Category::create(['code' => 'RAW', 'name' => 'Raw materials', 'default_valuation_method' => ValuationMethod::Avco]);
        $parent = Category::create(['code' => 'PIGMENTS', 'name' => 'Pigments', 'parent_id' => $grandparent->id]);
        $child = Category::create(['code' => 'REDS', 'name' => 'Reds', 'parent_id' => $parent->id]);

        $this->assertSame(ValuationMethod::Avco, $child->resolveDefaultValuationMethod());
    }

    public function test_default_valuation_method_prefers_the_nearer_ancestor(): void
    {
        $grandparent = Category::create(['code' => 'RAW', 'name' => 'Raw materials', 'default_valuation_method' => ValuationMethod::Avco]);
        $parent = Category::create([
            'code' => 'RESALE', 'name' => 'Resale goods',
            'parent_id' => $grandparent->id, 'default_valuation_method' => ValuationMethod::Fifo,
        ]);
        $child = Category::create(['code' => 'IMPORTED', 'name' => 'Imported paint', 'parent_id' => $parent->id]);

        $this->assertSame(ValuationMethod::Fifo, $child->resolveDefaultValuationMethod());
    }

    public function test_default_valuation_method_returns_null_when_nothing_in_the_chain_sets_it(): void
    {
        $parent = Category::create(['code' => 'RAW', 'name' => 'Raw materials']);
        $child = Category::create(['code' => 'PIGMENTS', 'name' => 'Pigments', 'parent_id' => $parent->id]);

        $this->assertNull($child->resolveDefaultValuationMethod());
    }

    public function test_a_category_cannot_be_set_as_its_own_parent(): void
    {
        $a = Category::create(['code' => 'A', 'name' => 'A']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cycle');

        $a->update(['parent_id' => $a->id]);
    }

    public function test_reparenting_a_category_under_its_own_descendant_is_rejected(): void
    {
        $a = Category::create(['code' => 'A', 'name' => 'A']);
        $b = Category::create(['code' => 'B', 'name' => 'B', 'parent_id' => $a->id]);
        $c = Category::create(['code' => 'C', 'name' => 'C', 'parent_id' => $b->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cycle');

        $a->update(['parent_id' => $c->id]);
    }

    public function test_reparenting_under_an_unrelated_existing_category_is_unaffected(): void
    {
        $a = Category::create(['code' => 'A', 'name' => 'A']);
        $b = Category::create(['code' => 'B', 'name' => 'B']);

        $a->update(['parent_id' => $b->id]);

        $this->assertSame($b->id, $a->fresh()->parent_id);
    }

    public function test_parent_id_must_resolve_to_a_category_that_exists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a category');

        Category::create(['code' => 'A', 'name' => 'A', 'parent_id' => 999999]);
    }

    public function test_parent_id_cannot_point_at_another_companys_category(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyCategory = Category::create(['code' => 'RAW', 'name' => 'Raw materials']);

        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a category');

        Category::create(['code' => 'RAW', 'name' => 'Raw materials', 'parent_id' => $otherCompanyCategory->id]);
    }
}
