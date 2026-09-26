<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Imports\Actions\CreateImportProfile;
use App\Modules\Imports\Actions\StageImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Support\ImportPurpose;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The inventory cutover HTTP surface — exposed per
 * docs/phases/phase-2-purchasing-stock.md#close-out. The workflow rules
 * themselves (reconciliation, separation of duties, negative-opening
 * evidence, immutability) are covered at the Action level by
 * InventoryCutoverWorkflowTest/InventoryCutoverNegativeOpeningTest/
 * InventoryCutoverOpeningStatePostingTest; this only proves the
 * controller/request layer wires them correctly. Batch attachment still
 * builds its ImportBatch by calling Import Actions directly — the Imports
 * module itself has no HTTP surface yet.
 */
class InventoryCutoverApiTest extends TestCase
{
    use RefreshDatabase;

    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach ([
            'inventory.cutover.create', 'inventory.cutover.stage', 'inventory.cutover.validate',
            'inventory.cutover.approve', 'inventory.cutover.commit', 'inventory.cutover.finalise',
            'inventory.cutover.negative_opening.request', 'inventory.cutover.negative_opening.approve',
        ] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->createReferenceData();
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/inventory-cutovers')->assertUnauthorized();
    }

    public function test_full_workflow_create_attach_validate_preview_approve_commit_finalise(): void
    {
        $batch = $this->validatedImportBatch([$this->positiveRow()]);
        $creator = $this->permittedUser('inventory.cutover.create');
        $stager = $this->permittedUser('inventory.cutover.stage');
        $validator = $this->permittedUser('inventory.cutover.validate');
        $approver = $this->permittedUser('inventory.cutover.approve');
        $committer = $this->permittedUser('inventory.cutover.commit');
        $finaliser = $this->permittedUser('inventory.cutover.finalise');

        $cutoverId = $this->actingAs($creator)->postJson('/api/v1/inventory-cutovers', $this->cutoverInput())
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')->json('data.id');

        $this->actingAs($stager)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/batches", ['import_batch_id' => $batch->id])
            ->assertCreated()->assertJsonPath('data.status', 'STAGED');

        $this->actingAs($validator)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/validate")
            ->assertOk()->assertJsonPath('data.status', 'VALIDATED');

        $this->actingAs($validator)->getJson("/api/v1/inventory-cutovers/{$cutoverId}/preview")
            ->assertOk()
            ->assertJsonPath('data.source_quantity', '10.0000')
            ->assertJsonPath('data.control_quantity', '10.0000');

        $this->actingAs($approver)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $this->actingAs($committer)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/commit")
            ->assertOk()->assertJsonPath('data.batches.0.status', 'COMMITTED');

        $this->actingAs($finaliser)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/finalise")
            ->assertOk()->assertJsonPath('data.status', 'FINALISED');

        $this->assertDatabaseHas('stock_moves', ['item_id' => Item::query()->where('code', 'sku-100')->value('id'), 'source_type' => 'INVENTORY_CUTOVER_OPENING']);
    }

    public function test_negative_opening_request_is_required_before_commit_and_then_unblocks_it(): void
    {
        $batch = $this->validatedImportBatch([$this->negativeRow()]);
        $creator = $this->permittedUser('inventory.cutover.create');
        $stager = $this->permittedUser('inventory.cutover.stage');
        $validator = $this->permittedUser('inventory.cutover.validate');
        $approver = $this->permittedUser('inventory.cutover.approve');
        $committer = $this->permittedUser('inventory.cutover.commit');
        $requester = $this->permittedUser('inventory.cutover.negative_opening.request');
        $exceptionApprover = $this->permittedUser('inventory.cutover.negative_opening.approve');

        $cutoverId = $this->actingAs($creator)->postJson('/api/v1/inventory-cutovers', $this->cutoverInput('SKU-NEG', '-5.0000', '-50.0000'))
            ->json('data.id');
        $this->actingAs($stager)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/batches", ['import_batch_id' => $batch->id])->assertCreated();
        $this->actingAs($validator)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/validate")->assertOk();
        $this->actingAs($approver)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/approve")->assertOk();

        $this->actingAs($committer)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/commit")->assertUnprocessable();

        $item = Item::query()->where('code', 'sku-neg')->firstOrFail();
        $exceptionId = $this->actingAs($requester)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/negative-openings", [
            'item_id' => $item->id, 'location_id' => $this->locationId, 'batch_no' => null,
            'provisional_unit_cost' => '9.0000',
            'reason' => 'Predecessor system closed this location negative per its final approved stock report.',
            'supporting_evidence' => ['document' => 'legacy-closing-report.pdf', 'page' => 4],
            'responsible_owner_id' => User::factory()->create()->id,
            'resolution_plan' => 'Receive the delayed inbound shipment already confirmed in transit.',
        ])->assertCreated()->assertJsonPath('data.status', 'REQUESTED')->json('data.id');

        $this->actingAs($exceptionApprover)->postJson("/api/v1/inventory-cutover-negative-openings/{$exceptionId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.item.code', 'sku-neg')->assertJsonPath('data.location.code', 'stock');

        $this->actingAs($committer)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/commit")
            ->assertOk()->assertJsonPath('data.batches.0.status', 'COMMITTED');

        $this->assertDatabaseHas('negative_stock_shortages', ['item_id' => $item->id, 'status' => 'OPEN_CUTOVER_SHORTAGE']);

        $this->actingAs($committer)->getJson("/api/v1/inventory-cutovers/{$cutoverId}?include=negativeOpenings")
            ->assertOk()
            ->assertJsonPath('data.negative_openings.0.item.code', 'sku-neg')
            ->assertJsonPath('data.negative_openings.0.location.code', 'stock');
    }

    public function test_store_validates_shape_and_rejects_an_unpermitted_actor(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/inventory-cutovers', ['mode' => 'BOGUS'])
            ->assertUnprocessable()->assertJsonValidationErrors(['mode', 'source_system', 'target_valuation_policy']);

        $this->actingAs($user)->postJson('/api/v1/inventory-cutovers', $this->cutoverInput())
            ->assertUnprocessable()->assertJsonValidationErrors('created_by');
    }

    public function test_approver_cannot_be_the_creator_or_validator(): void
    {
        $batch = $this->validatedImportBatch([$this->positiveRow()]);
        $creatorAndValidator = $this->permittedUser(['inventory.cutover.create', 'inventory.cutover.stage', 'inventory.cutover.validate', 'inventory.cutover.approve']);

        $cutoverId = $this->actingAs($creatorAndValidator)->postJson('/api/v1/inventory-cutovers', $this->cutoverInput())->json('data.id');
        $this->actingAs($creatorAndValidator)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/batches", ['import_batch_id' => $batch->id])->assertCreated();
        $this->actingAs($creatorAndValidator)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/validate")->assertOk();

        $this->actingAs($creatorAndValidator)->postJson("/api/v1/inventory-cutovers/{$cutoverId}/approve")
            ->assertUnprocessable();
    }

    public function test_index_filters_by_status(): void
    {
        $creator = $this->permittedUser('inventory.cutover.create');
        $cutoverId = $this->actingAs($creator)->postJson('/api/v1/inventory-cutovers', $this->cutoverInput())->json('data.id');

        $this->actingAs($creator)->getJson('/api/v1/inventory-cutovers?filter[status]=DRAFT')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cutoverId);

        $this->getJson('/api/v1/inventory-cutovers?filter[status]=BOGUS')
            ->assertUnprocessable()->assertJsonValidationErrors('filter.status');
    }

    public function test_another_companys_cutover_is_not_visible(): void
    {
        $creator = $this->permittedUser('inventory.cutover.create');
        $cutoverId = $this->actingAs($creator)->postJson('/api/v1/inventory-cutovers', $this->cutoverInput())->json('data.id');

        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->actingAs(User::factory()->create())->getJson("/api/v1/inventory-cutovers/{$cutoverId}")->assertNotFound();
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function validatedImportBatch(array $rows): ImportBatch
    {
        $profile = app(CreateImportProfile::class)('Legacy stock close '.uniqid(), ImportPurpose::InventoryCutover, 'OPENING_STATE', [
            'source_id' => 'source_record_id', 'sku' => 'item_code', 'warehouse' => 'warehouse_code',
            'location' => 'location_code', 'method' => 'source_valuation_method', 'scope' => 'source_avco_scope',
            'pool' => 'source_pool_identity', 'qty' => 'quantity', 'uom' => 'uom_code', 'cost' => 'unit_cost',
            'value' => 'amount', 'base_value' => 'base_amount', 'currency' => 'currency_code', 'fx' => 'fx_rate',
            'batch' => 'batch_no', 'serial' => 'serial_no', 'effective' => 'source_effective_at',
        ]);
        $batch = app(StageImportBatch::class)($profile, ImportPurpose::InventoryCutover, 'cutover-batch-'.uniqid(), 'opening.csv', $rows, User::factory()->create());

        return app(ValidateImportBatch::class)($batch);
    }

    /** @return array<string, mixed> */
    private function positiveRow(): array
    {
        return [
            'source_id' => 'CUT-POS-1', 'sku' => 'SKU-100', 'warehouse' => 'MAIN', 'location' => 'STOCK',
            'method' => 'AVCO', 'scope' => 'COMPANY_ITEM', 'pool' => 'SKU-100', 'qty' => '10.0000',
            'uom' => 'EA', 'cost' => '5.0000', 'value' => '50.0000', 'base_value' => '50.0000',
            'currency' => 'PKR', 'fx' => '1.000000000', 'batch' => null, 'serial' => null, 'effective' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function negativeRow(): array
    {
        return [
            'source_id' => 'CUT-NEG-1', 'sku' => 'SKU-NEG', 'warehouse' => 'MAIN', 'location' => 'STOCK',
            'method' => 'AVCO', 'scope' => 'COMPANY_ITEM', 'pool' => 'SKU-NEG', 'qty' => '-5.0000',
            'uom' => 'EA', 'cost' => '10.0000', 'value' => '-50.0000', 'base_value' => '-50.0000',
            'currency' => 'PKR', 'fx' => '1.000000000', 'batch' => null, 'serial' => null, 'effective' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function cutoverInput(string $itemCode = 'SKU-100', string $controlQuantity = '10.0000', string $controlValue = '50.0000'): array
    {
        return [
            'mode' => 'OPENING_STATE', 'cutover_at' => '2026-09-01T00:00:00+00:00',
            'source_system' => 'Legacy ERP',
            'target_valuation_policy' => [$itemCode => ['method' => 'AVCO', 'scope' => 'COMPANY_ITEM']],
            'control_quantity' => $controlQuantity, 'control_value' => $controlValue, 'base_control_value' => $controlValue,
        ];
    }

    private function createReferenceData(): void
    {
        Currency::query()->create(['code' => 'PKR', 'name' => 'Pakistan Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        $uom = Uom::query()->create(['code' => 'EA', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1.000000000', 'is_base' => true]);
        $account = ChartOfAccount::query()->create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]);
        $category = Category::query()->create(['code' => 'stock', 'name' => 'Stock', 'default_valuation_method' => ValuationMethod::Avco, 'inventory_account_id' => $account->id]);

        foreach (['SKU-100' => 'Cutover positive item', 'SKU-NEG' => 'Cutover negative item'] as $code => $name) {
            Item::query()->create([
                'code' => $code, 'name' => $name, 'type' => ItemType::Raw, 'stock_uom_id' => $uom->id,
                'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'tracking' => ItemTracking::None,
                'avco_pool_scope' => 'COMPANY_ITEM', 'category_id' => $category->id,
            ]);
        }

        app(CreateWarehouse::class)(['code' => 'MAIN', 'name' => 'Main warehouse', 'is_default' => true, 'location_code' => 'STOCK', 'location_name' => 'Stock']);
        $this->locationId = (int) Location::query()->where('code', 'stock')->value('id');
        Company::query()->findOrFail(1)->update(['base_currency_id' => Currency::query()->where('code', 'PKR')->value('id')]);
    }

    /** @param  string|array<int, string>  $permissions */
    private function permittedUser(string|array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }
}
