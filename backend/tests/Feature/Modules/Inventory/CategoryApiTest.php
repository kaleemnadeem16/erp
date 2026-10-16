<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Support\CompanyContext;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function actingAs(UserContract $user, $guard = null): static
    {
        PermissionedActor::grant($user, ['inventory.category.manage']);

        return parent::actingAs($user, $guard);
    }

    protected function actingAsUnprivileged(UserContract $user, $guard = null): static
    {
        return parent::actingAs($user, $guard);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/categories')->assertUnauthorized();
    }

    public function test_category_writes_require_manage_permission_but_reads_remain_available(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['code' => 'READ', 'name' => 'Readable']);

        $this->actingAsUnprivileged($user)->getJson('/api/v1/categories')->assertOk();
        $this->getJson("/api/v1/categories/{$category->id}")->assertOk();
        $this->postJson('/api/v1/categories', ['code' => 'DENIED', 'name' => 'Denied'])->assertForbidden();
        $this->patchJson("/api/v1/categories/{$category->id}", ['name' => 'Denied'])->assertForbidden();
        $this->deleteJson("/api/v1/categories/{$category->id}")->assertForbidden();
    }

    public function test_a_category_can_be_created_and_fetched(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'code' => 'RAW',
            'name' => 'Raw materials',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.code', 'RAW');
        $id = $response->json('data.id');

        $this->getJson("/api/v1/categories/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Raw materials');
    }

    public function test_duplicate_code_is_rejected_with_a_422(): void
    {
        $user = User::factory()->create();
        Category::create(['code' => 'RAW', 'name' => 'Raw materials']);

        $this->actingAs($user)->postJson('/api/v1/categories', [
            'code' => 'RAW',
            'name' => 'Duplicate',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_a_nonexistent_parent_id_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/categories', [
            'code' => 'RAW',
            'name' => 'Raw materials',
            'parent_id' => 999999,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_another_companys_parent_id_is_rejected_with_a_422_not_a_500(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Other Co', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $otherCompanyCategory = Category::create(['code' => 'RAW', 'name' => 'Raw materials']);
        $this->app->instance(CompanyContext::class, new CompanyContext);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/categories', [
            'code' => 'RAW',
            'name' => 'Raw materials',
            'parent_id' => $otherCompanyCategory->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    /**
     * Rule::exists() can't detect a hierarchy cycle — that's tree-walking logic
     * only Category's own guard performs (GuardsCompanyScopedHierarchy). This
     * proves the API layer surfaces that guard's rejection as a 422 too, via
     * App\Exceptions\DomainInvariantException, not an uncaught 500.
     */
    public function test_reparenting_under_its_own_descendant_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();
        $a = Category::create(['code' => 'A', 'name' => 'A']);
        $b = Category::create(['code' => 'B', 'name' => 'B', 'parent_id' => $a->id]);

        $this->actingAs($user)->patchJson("/api/v1/categories/{$a->id}", [
            'parent_id' => $b->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_index_rejects_invalid_filters_and_pagination(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/categories?filter[parent_id]=not-an-id')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filter.parent_id');

        $this->getJson('/api/v1/categories?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_index_can_filter_to_top_level_categories_only(): void
    {
        $user = User::factory()->create();
        $parent = Category::create(['code' => 'RAW', 'name' => 'Raw materials']);
        Category::create(['code' => 'PIGMENTS', 'name' => 'Pigments', 'parent_id' => $parent->id]);

        $response = $this->actingAs($user)->getJson('/api/v1/categories?filter[parent_id]=0');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.code', 'RAW');
    }

    public function test_index_can_filter_by_valuation_method_and_children(): void
    {
        $user = User::factory()->create();
        $parent = Category::create(['code' => 'RAW', 'name' => 'Raw materials', 'default_valuation_method' => 'AVCO']);
        $child = Category::create(['code' => 'PIGMENTS', 'name' => 'Pigments', 'parent_id' => $parent->id, 'default_valuation_method' => null]);

        $avcoResponse = $this->actingAs($user)->getJson('/api/v1/categories?filter[default_valuation_method]=AVCO');
        $avcoResponse->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'RAW');

        $inheritedResponse = $this->actingAs($user)->getJson('/api/v1/categories?filter[default_valuation_method]=INHERITED');
        $inheritedResponse->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'PIGMENTS');

        $hasChildrenResponse = $this->actingAs($user)->getJson('/api/v1/categories?filter[has_children]=true');
        $hasChildrenResponse->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'RAW');

        $leafResponse = $this->actingAs($user)->getJson('/api/v1/categories?filter[has_children]=false');
        $leafResponse->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'PIGMENTS');
    }

    public function test_unprivileged_user_filtering_by_account_is_forbidden(): void
    {
        $user = User::factory()->create();
        $this->actingAsUnprivileged($user)
            ->getJson('/api/v1/categories?filter[income_account_id]=1')
            ->assertForbidden();
    }

    public function test_show_can_include_children(): void
    {
        $user = User::factory()->create();
        $parent = Category::create(['code' => 'RAW', 'name' => 'Raw materials']);
        Category::create(['code' => 'PIGMENTS', 'name' => 'Pigments', 'parent_id' => $parent->id]);

        $response = $this->actingAs($user)->getJson("/api/v1/categories/{$parent->id}?include=children");

        $response->assertOk();
        $response->assertJsonCount(1, 'data.children');
        $response->assertJsonPath('data.children.0.code', 'PIGMENTS');
    }

    public function test_a_category_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['code' => 'RAW', 'name' => 'Raw materials']);

        $this->actingAs($user)->deleteJson("/api/v1/categories/{$category->id}")->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    /**
     * categories.id has no ON DELETE CASCADE from items.category_id (RESTRICT is
     * Postgres's default) — this proves that FK violation surfaces as a clean 409,
     * not an uncaught 500, via the global QueryException(23503) handler in
     * bootstrap/app.php rather than a per-endpoint pre-check.
     */
    public function test_deleting_a_category_still_referenced_by_an_item_returns_409_not_500(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['code' => 'RAW', 'name' => 'Raw materials']);
        $uom = Uom::create([
            'code' => 'KG', 'name' => 'Kilogram', 'dimension' => 'MASS',
            'factor_to_base' => '1', 'is_base' => true,
        ]);
        Item::create([
            'code' => 'pigment-red', 'name' => 'Red pigment', 'type' => 'RAW',
            'stock_uom_id' => $uom->id, 'category_id' => $category->id, 'valuation_method' => 'AVCO',
        ]);

        $this->actingAs($user)->deleteJson("/api/v1/categories/{$category->id}")->assertStatus(409);
    }

    public function test_another_companys_category_is_not_visible(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Other Co', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $otherCompanyCategory = Category::create(['code' => 'RAW', 'name' => 'Raw materials']);
        $this->app->instance(CompanyContext::class, new CompanyContext);

        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/api/v1/categories/{$otherCompanyCategory->id}")->assertNotFound();
    }
}
