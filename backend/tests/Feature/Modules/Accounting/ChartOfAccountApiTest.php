<?php

namespace Tests\Feature\Modules\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Models\Category;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChartOfAccountApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/chart-of-accounts')->assertUnauthorized();
    }

    public function test_a_chart_of_account_can_be_created_and_fetched(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/chart-of-accounts', [
            'code' => '1000',
            'name' => 'Cash',
            'type' => 'ASSET',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.code', '1000');
        $response->assertJsonPath('data.status', 'ACTIVE');
        $response->assertJsonPath('data.is_postable', true);
        $id = $response->json('data.id');

        $this->getJson("/api/v1/chart-of-accounts/{$id}")->assertOk()->assertJsonPath('data.name', 'Cash');
    }

    public function test_duplicate_code_is_rejected_with_a_422(): void
    {
        $user = User::factory()->create();
        ChartOfAccount::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset]);

        $this->actingAs($user)->postJson('/api/v1/chart-of-accounts', [
            'code' => '1000',
            'name' => 'Duplicate',
            'type' => 'ASSET',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_an_invalid_type_is_rejected_with_a_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/chart-of-accounts', [
            'code' => '1000',
            'name' => 'Cash',
            'type' => 'NOT_A_TYPE',
        ])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_a_nonexistent_parent_id_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/chart-of-accounts', [
            'code' => '1010', 'name' => 'Cash', 'type' => 'ASSET', 'parent_id' => 999999,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    /**
     * Rule::exists() can't detect a hierarchy cycle — that's tree-walking logic
     * only ChartOfAccount's own guard performs (GuardsCompanyScopedHierarchy).
     * This proves the API layer surfaces that guard's rejection as a 422 too,
     * via App\Exceptions\DomainInvariantException, not an uncaught 500.
     */
    public function test_reparenting_under_its_own_descendant_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();
        $a = ChartOfAccount::create(['code' => '1000', 'name' => 'Assets', 'type' => AccountType::Asset]);
        $b = ChartOfAccount::create(['code' => '1010', 'name' => 'Cash', 'type' => AccountType::Asset, 'parent_id' => $a->id]);

        $this->actingAs($user)->patchJson("/api/v1/chart-of-accounts/{$a->id}", [
            'parent_id' => $b->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_index_rejects_invalid_filters_and_pagination(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/chart-of-accounts?filter[type]=NOT_A_TYPE')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filter.type');

        $this->getJson('/api/v1/chart-of-accounts?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_index_can_filter_to_top_level_accounts_only(): void
    {
        $user = User::factory()->create();
        $parent = ChartOfAccount::create(['code' => '1000', 'name' => 'Assets', 'type' => AccountType::Asset]);
        ChartOfAccount::create(['code' => '1010', 'name' => 'Cash', 'type' => AccountType::Asset, 'parent_id' => $parent->id]);

        $response = $this->actingAs($user)->getJson('/api/v1/chart-of-accounts?filter[parent_id]=0');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.code', '1000');
    }

    public function test_a_chart_of_account_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $account = ChartOfAccount::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset]);

        $this->actingAs($user)->deleteJson("/api/v1/chart-of-accounts/{$account->id}")->assertNoContent();

        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $account->id]);
    }

    /**
     * chart_of_accounts.id has no ON DELETE CASCADE from categories.income_account_id
     * (RESTRICT is Postgres's default) — this proves that FK violation surfaces
     * as a clean 409, not an uncaught 500, via the global QueryException(23503)
     * handler in bootstrap/app.php rather than a per-endpoint pre-check.
     */
    public function test_deleting_an_account_still_referenced_by_a_category_returns_409_not_500(): void
    {
        $user = User::factory()->create();
        $account = ChartOfAccount::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income]);
        Category::create(['code' => 'RAW', 'name' => 'Raw materials', 'income_account_id' => $account->id]);

        $this->actingAs($user)->deleteJson("/api/v1/chart-of-accounts/{$account->id}")->assertStatus(409);
    }

    public function test_another_companys_chart_of_account_is_not_visible(): void
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
        $otherAccount = ChartOfAccount::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset]);
        $this->app->instance(CompanyContext::class, new CompanyContext);

        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/api/v1/chart-of-accounts/{$otherAccount->id}")->assertNotFound();
    }
}
