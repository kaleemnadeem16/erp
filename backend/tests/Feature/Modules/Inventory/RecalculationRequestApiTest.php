<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/**
 * The stock recalculation HTTP surface — exposed per
 * docs/phases/phase-2-purchasing-stock.md#close-out. The workflow rules
 * themselves (replay/staleness, risk routing, approval expiry/renewal,
 * regeneration pausing, execution posting) are covered exhaustively at the
 * Action level by the existing 10 Recalculation-, Execute- and
 * Decide-prefixed test files; this only proves the controller/request layer wires them
 * correctly, mirroring PhysicalCountApiTest/InventoryCutoverApiTest/
 * NegativeStockOverrideRequestApiTest.
 */
class RecalculationRequestApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach ([
            'inventory.recalculation.request', 'inventory.recalculation.approve',
            'inventory.recalculation.approve-high-risk', 'inventory.recalculation.execute',
            'inventory.recalculation.review-regeneration',
            'inventory.valuation.view',
        ] as $permission) {
            Permission::findOrCreate($permission);
        }
        Company::query()->findOrFail(1)->update([
            'authorised_forward_recalculation_enabled' => true,
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2020-01-01',
            'recalculation_approval_ttl_hours' => 24,
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/recalculation-requests')->assertUnauthorized();
    }

    public function test_recalculation_reads_require_a_workflow_duty_or_valuation_visibility(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        $this->conflictingHistory($item, $uom, $location, 9);
        $requester = $this->permittedUser('inventory.recalculation.request');
        $requestId = $this->actingAs($requester)
            ->postJson('/api/v1/recalculation-requests', $this->input($item, $uom, $location, 9))
            ->assertCreated()->json('data.id');

        $unrelated = User::factory()->create();
        $this->actingAs($unrelated)->getJson('/api/v1/recalculation-requests')->assertForbidden();
        $this->getJson("/api/v1/recalculation-requests/{$requestId}")->assertForbidden();

        foreach (['inventory.recalculation.approve', 'inventory.recalculation.approve-high-risk', 'inventory.recalculation.execute', 'inventory.recalculation.review-regeneration', 'inventory.valuation.view'] as $capability) {
            $reader = $this->permittedUser($capability);
            $this->actingAs($reader)->getJson('/api/v1/recalculation-requests')
                ->assertOk()->assertJsonPath('data.0.id', $requestId);
            $this->getJson("/api/v1/recalculation-requests/{$requestId}")
                ->assertOk()->assertJsonStructure(['data' => ['impact_versions' => [['id']]]]);
        }
    }

    public function test_normal_risk_lifecycle_request_decide_execute(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        $this->conflictingHistory($item, $uom, $location, 1);
        $requester = $this->permittedUser('inventory.recalculation.request');

        $requestId = $this->actingAs($requester)->postJson('/api/v1/recalculation-requests', $this->input($item, $uom, $location, 1))
            ->assertCreated()
            ->assertJsonPath('data.impact_versions.0.version_no', 1)
            ->assertJsonPath('data.impact_versions.0.risk_classification', 'NORMAL_RISK')
            ->assertJsonPath('data.impact_versions.0.required_approval_count', 1)
            ->assertJsonPath('data.impact_versions.0.is_decided', false)
            ->json('data.id');
        $versionId = $this->getJson("/api/v1/recalculation-requests/{$requestId}")
            ->assertOk()->json('data.impact_versions.0.id');

        $approver = $this->permittedUser('inventory.recalculation.approve');
        $this->actingAs($approver)->postJson("/api/v1/recalculation-impact-versions/{$versionId}/decide", ['decision' => 'APPROVED', 'notes' => 'Evidence checked'])
            ->assertOk()
            ->assertJsonPath('data.impact_versions.0.is_fully_approved', true)
            ->assertJsonPath('data.impact_versions.0.has_unexpired_full_approval', true)
            ->assertJsonPath('data.impact_versions.0.approvals.0.decision', 'APPROVED');

        $executor = $this->permittedUser('inventory.recalculation.execute');
        $this->actingAs($executor)->postJson("/api/v1/recalculation-impact-versions/{$versionId}/execute")
            ->assertOk()
            ->assertJsonPath('data.impact_versions.0.execution.executed_by', $executor->id);

        $this->assertDatabaseHas('stock_moves', ['item_id' => $item->id, 'source_type' => 'TEST_BACKDATED_RECEIPT_1']);
    }

    public function test_high_risk_version_needs_two_independent_approvers(): void
    {
        Company::query()->findOrFail(1)->update(['recalculation_high_risk_threshold_base_amount' => '1.0000']);
        [$item, $uom, $location] = $this->makeAvcoItem();
        $this->conflictingHistory($item, $uom, $location, 2);
        $requester = $this->permittedUser('inventory.recalculation.request');

        $requestId = $this->actingAs($requester)->postJson('/api/v1/recalculation-requests', $this->input($item, $uom, $location, 2))
            ->assertCreated()->assertJsonPath('data.impact_versions.0.risk_classification', 'HIGH_RISK')
            ->assertJsonPath('data.impact_versions.0.required_approval_count', 2)
            ->json('data.id');
        $versionId = $this->getJson("/api/v1/recalculation-requests/{$requestId}")->json('data.impact_versions.0.id');

        $firstApprover = $this->permittedUser('inventory.recalculation.approve');
        $this->actingAs($firstApprover)->postJson("/api/v1/recalculation-impact-versions/{$versionId}/decide", ['decision' => 'APPROVED', 'notes' => 'First check'])
            ->assertOk()->assertJsonPath('data.impact_versions.0.has_unexpired_full_approval', false);

        $executor = $this->permittedUser('inventory.recalculation.execute');
        $this->actingAs($executor)->postJson("/api/v1/recalculation-impact-versions/{$versionId}/execute")->assertUnprocessable();

        $secondApprover = $this->permittedUser(['inventory.recalculation.approve', 'inventory.recalculation.approve-high-risk']);
        $this->actingAs($secondApprover)->postJson("/api/v1/recalculation-impact-versions/{$versionId}/decide", ['decision' => 'APPROVED', 'notes' => 'Second, independent check'])
            ->assertOk()->assertJsonPath('data.impact_versions.0.has_unexpired_full_approval', true);

        $this->actingAs($executor)->postJson("/api/v1/recalculation-impact-versions/{$versionId}/execute")->assertOk();
    }

    public function test_a_rejected_version_can_never_execute(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        $this->conflictingHistory($item, $uom, $location, 3);
        $requester = $this->permittedUser('inventory.recalculation.request');
        $requestId = $this->actingAs($requester)->postJson('/api/v1/recalculation-requests', $this->input($item, $uom, $location, 3))->json('data.id');
        $versionId = $this->getJson("/api/v1/recalculation-requests/{$requestId}")->json('data.impact_versions.0.id');

        $approver = $this->permittedUser('inventory.recalculation.approve');
        $this->actingAs($approver)->postJson("/api/v1/recalculation-impact-versions/{$versionId}/decide", ['decision' => 'REJECTED', 'notes' => 'Not defensible'])
            ->assertOk()->assertJsonPath('data.impact_versions.0.is_rejected', true);

        $executor = $this->permittedUser('inventory.recalculation.execute');
        $this->actingAs($executor)->postJson("/api/v1/recalculation-impact-versions/{$versionId}/execute")->assertUnprocessable();
    }

    public function test_resume_regeneration_on_a_version_that_is_not_paused_fails(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        $this->conflictingHistory($item, $uom, $location, 4);
        $requester = $this->permittedUser('inventory.recalculation.request');
        $requestId = $this->actingAs($requester)->postJson('/api/v1/recalculation-requests', $this->input($item, $uom, $location, 4))->json('data.id');
        $versionId = $this->getJson("/api/v1/recalculation-requests/{$requestId}")->json('data.impact_versions.0.id');

        $reviewer = $this->permittedUser('inventory.recalculation.review-regeneration');
        $this->actingAs($reviewer)->postJson("/api/v1/recalculation-impact-versions/{$versionId}/resume-regeneration", ['notes' => 'Coordinated'])
            ->assertUnprocessable();
    }

    public function test_index_filter_validation(): void
    {
        $this->actingAs($this->permittedUser('inventory.recalculation.request'))->getJson('/api/v1/recalculation-requests?filter[movement_kind]=BOGUS')
            ->assertUnprocessable()->assertJsonValidationErrors('filter.movement_kind');
    }

    public function test_another_companys_request_is_not_visible(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        $this->conflictingHistory($item, $uom, $location, 5);
        $requester = $this->permittedUser('inventory.recalculation.request');
        $requestId = $this->actingAs($requester)->postJson('/api/v1/recalculation-requests', $this->input($item, $uom, $location, 5))->json('data.id');

        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->actingAs($this->permittedUser('inventory.recalculation.request'))->getJson("/api/v1/recalculation-requests/{$requestId}")->assertNotFound();
    }

    /** @return array{0: Item, 1: Uom, 2: Location} */
    private function makeAvcoItem(): array
    {
        $currency = Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $uom = Uom::query()->firstOrCreate(['code' => 'ea'], ['name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::query()->create(['code' => 'raw-'.uniqid(), 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $item = Item::query()->create([
            'code' => 'item-'.uniqid(), 'name' => 'Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'avco_pool_scope' => AvcoPoolScope::CompanyItem,
            'inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id,
        ]);
        $location = Location::query()->where('code', 'main')->first()
            ?? app(CreateWarehouse::class)(['code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main'], PurchasingActor::make(['inventory.warehouse.manage']))->locations()->sole();

        return [$item, $uom, $location];
    }

    /** Receipt 5@10 on Jan 1, an issue of 2 on Jan 10 — a later movement for the backdated request to conflict with. */
    private function conflictingHistory(Item $item, Uom $uom, Location $location, int $sourceId): void
    {
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-01'), 'source_type' => 'TEST_RECEIPT_'.$sourceId, 'source_id' => $sourceId,
            'unit_cost' => '10.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '10.0000',
        ]);
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '2.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_ISSUE_'.$sourceId, 'source_id' => $sourceId,
        ]);
    }

    /** @return array<string, mixed> */
    private function input(Item $item, Uom $uom, Location $location, int $sourceId): array
    {
        return [
            'item_id' => $item->id, 'location_id' => $location->id,
            'qty' => '10.0000', 'unit_cost' => '20.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '20.0000', 'moved_at' => '2026-01-05T00:00:00Z',
            'source_type' => 'TEST_BACKDATED_RECEIPT_'.$sourceId, 'source_id' => $sourceId,
        ];
    }

    /** @param  string|array<int, string>  $permissions */
    private function permittedUser(string|array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }
}
