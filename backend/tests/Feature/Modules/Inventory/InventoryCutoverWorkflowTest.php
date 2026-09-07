<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Imports\Actions\CreateImportProfile;
use App\Modules\Imports\Actions\StageImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportProfile;
use App\Modules\Imports\Support\ImportPurpose;
use App\Modules\Inventory\Actions\ApproveInventoryCutover;
use App\Modules\Inventory\Actions\AttachInventoryCutoverBatch;
use App\Modules\Inventory\Actions\CreateInventoryCutover;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\FinaliseInventoryCutover;
use App\Modules\Inventory\Actions\PreviewInventoryCutover;
use App\Modules\Inventory\Actions\ValidateInventoryCutover;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\InventoryCutoverPolicyTransition;
use App\Modules\Inventory\Models\InventorySourceFact;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\InventoryCutoverBatchStatus;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryCutoverWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['inventory.cutover.create', 'inventory.cutover.stage', 'inventory.cutover.validate', 'inventory.cutover.approve', 'inventory.cutover.finalise'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->createInventoryReferences();
    }

    public function test_validated_batch_is_staged_as_immutable_source_facts_previewed_and_approved(): void
    {
        [$profile, $batch] = $this->validatedImportBatch();
        $creator = $this->permittedUser('inventory.cutover.create');
        $cutover = app(CreateInventoryCutover::class)($this->cutoverInput(), $creator);
        $link = app(AttachInventoryCutoverBatch::class)($cutover, $batch, $this->permittedUser('inventory.cutover.stage'));

        $this->assertSame('10.0000', $link->source_quantity);
        $this->assertSame('125.0000', $link->source_value);
        $fact = InventorySourceFact::query()->sole();
        $this->assertSame($batch->rows->sole()->id, $fact->import_batch_row_id);
        $this->assertSame('LEGACY-ROW-1', $fact->source_record_id);
        $this->assertSame('AVCO', $fact->source_valuation_method->value);
        $this->assertSame('SKU-100', $fact->source_payload['raw']['sku']);

        $preview = app(PreviewInventoryCutover::class)($cutover);
        $this->assertSame('10.0000', $preview['source_quantity']);
        $this->assertSame('125.0000', $preview['base_source_value']);

        $validated = app(ValidateInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.validate'));
        $transition = InventoryCutoverPolicyTransition::query()->sole();
        $this->assertSame('AVCO', $transition->source_valuation_method->value);
        $this->assertSame('COMPANY_ITEM', $transition->source_avco_scope->value);
        $this->assertSame('AVCO', $transition->target_valuation_method->value);
        $this->assertSame('COMPANY_ITEM', $transition->target_avco_scope->value);
        $this->assertTrue($transition->effective_at->equalTo($validated->cutover_at));
        $this->assertArrayHasKey('sku-100', $validated->target_valuation_policy);
        $approved = app(ApproveInventoryCutover::class)($validated, $this->permittedUser('inventory.cutover.approve'));
        $this->assertSame('APPROVED', $approved->status->value);
        $this->assertNotNull($approved->approved_at);

        $retry = app(AttachInventoryCutoverBatch::class)($cutover->refresh(), $batch, $this->permittedUser('inventory.cutover.stage'));
        $this->assertSame($link->id, $retry->id);
        $this->assertDatabaseCount('inventory_cutover_batches', 1);
        $this->assertDatabaseCount('inventory_source_facts', 1);
        $this->assertSame($profile->id, $batch->import_profile_id);
    }

    public function test_approval_is_independent_and_every_workflow_step_has_a_dedicated_permission(): void
    {
        $this->expectException(DomainInvariantException::class);
        app(CreateInventoryCutover::class)($this->cutoverInput(), User::factory()->create());
    }

    public function test_creator_or_validator_cannot_approve(): void
    {
        [, $batch] = $this->validatedImportBatch();
        $creator = $this->permittedUser(['inventory.cutover.create', 'inventory.cutover.approve']);
        $cutover = app(CreateInventoryCutover::class)($this->cutoverInput(), $creator);
        app(AttachInventoryCutoverBatch::class)($cutover, $batch, $this->permittedUser('inventory.cutover.stage'));
        app(ValidateInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.validate'));

        $this->expectException(DomainInvariantException::class);
        app(ApproveInventoryCutover::class)($cutover->refresh(), $creator);
    }

    public function test_validation_rejects_control_totals_that_do_not_reconcile(): void
    {
        [, $batch] = $this->validatedImportBatch();
        $input = $this->cutoverInput();
        $input['base_control_value'] = '124.9999';
        $cutover = app(CreateInventoryCutover::class)($input, $this->permittedUser('inventory.cutover.create'));
        app(AttachInventoryCutoverBatch::class)($cutover, $batch, $this->permittedUser('inventory.cutover.stage'));

        $this->expectException(DomainInvariantException::class);
        app(ValidateInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.validate'));
    }

    public function test_finalisation_waits_for_the_opening_state_action_to_commit_every_batch(): void
    {
        [, $batch] = $this->validatedImportBatch();
        $cutover = app(CreateInventoryCutover::class)($this->cutoverInput(), $this->permittedUser('inventory.cutover.create'));
        $link = app(AttachInventoryCutoverBatch::class)($cutover, $batch, $this->permittedUser('inventory.cutover.stage'));
        $cutover = app(ValidateInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.validate'));
        $cutover = app(ApproveInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.approve'));
        $finaliser = $this->permittedUser('inventory.cutover.finalise');

        try {
            app(FinaliseInventoryCutover::class)($cutover, $finaliser);
            $this->fail('Expected finalisation to wait for the opening-state commit Action.');
        } catch (DomainInvariantException) {
            $this->assertSame('APPROVED', $cutover->refresh()->status->value);
        }

        $link->refresh()->update(['status' => InventoryCutoverBatchStatus::Committed]);
        $finalised = app(FinaliseInventoryCutover::class)($cutover, $finaliser);
        $this->assertSame('FINALISED', $finalised->status->value);
        $this->assertNotNull($finalised->finalised_at);
    }

    public function test_only_one_non_failed_cutover_can_exist_for_a_company(): void
    {
        $creator = $this->permittedUser('inventory.cutover.create');
        app(CreateInventoryCutover::class)($this->cutoverInput(), $creator);

        $this->expectException(QueryException::class);
        app(CreateInventoryCutover::class)($this->cutoverInput(), $creator);
    }

    public function test_source_facts_are_database_immutable(): void
    {
        [, $batch] = $this->validatedImportBatch();
        $cutover = app(CreateInventoryCutover::class)($this->cutoverInput(), $this->permittedUser('inventory.cutover.create'));
        app(AttachInventoryCutoverBatch::class)($cutover, $batch, $this->permittedUser('inventory.cutover.stage'));
        $fact = InventorySourceFact::query()->sole();

        $this->expectException(QueryException::class);
        DB::table('inventory_source_facts')->where('id', $fact->id)->update(['qty' => '99.0000']);
    }

    public function test_policy_transitions_are_database_immutable_and_idempotent(): void
    {
        [, $batch] = $this->validatedImportBatch();
        $input = $this->cutoverInput();
        $input['target_valuation_policy'] = [
            'SKU-100' => ['method' => 'FIFO', 'revaluation_basis' => ['kind' => 'SYNTHETIC_LAYER']],
        ];
        $cutover = app(CreateInventoryCutover::class)($input, $this->permittedUser('inventory.cutover.create'));
        app(AttachInventoryCutoverBatch::class)($cutover, $batch, $this->permittedUser('inventory.cutover.stage'));
        app(ValidateInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.validate'));
        app(ValidateInventoryCutover::class)($cutover->refresh(), $this->permittedUser('inventory.cutover.validate'));
        $this->assertDatabaseCount('inventory_cutover_policy_transitions', 1);
        $transition = InventoryCutoverPolicyTransition::query()->sole();
        $this->assertSame('AVCO', $transition->source_valuation_method->value);
        $this->assertSame('FIFO', $transition->target_valuation_method->value);
        $this->assertNull($transition->target_avco_scope);
        $this->assertSame(['kind' => 'SYNTHETIC_LAYER'], $transition->revaluation_basis);

        $this->expectException(QueryException::class);
        DB::table('inventory_cutover_policy_transitions')->where('id', $transition->id)
            ->update(['target_valuation_method' => 'FIFO', 'target_avco_scope' => null]);
    }

    /** @return array{ImportProfile, ImportBatch} */
    private function validatedImportBatch(): array
    {
        $profile = app(CreateImportProfile::class)('Cutover opening', ImportPurpose::InventoryCutover, 'OPENING_STATE', [
            'source_id' => 'source_record_id', 'sku' => 'item_code', 'warehouse' => 'warehouse_code',
            'location' => 'location_code', 'method' => 'source_valuation_method', 'scope' => 'source_avco_scope',
            'pool' => 'source_pool_identity', 'qty' => 'quantity', 'uom' => 'uom_code', 'cost' => 'unit_cost',
            'value' => 'amount', 'base_value' => 'base_amount', 'currency' => 'currency_code', 'fx' => 'fx_rate',
            'batch' => 'batch_no', 'serial' => 'serial_no', 'effective' => 'source_effective_at',
        ]);
        $batch = app(StageImportBatch::class)($profile, ImportPurpose::InventoryCutover, 'cutover-batch-1', 'opening.csv', [[
            'source_id' => 'LEGACY-ROW-1', 'sku' => 'SKU-100', 'warehouse' => 'MAIN', 'location' => 'STOCK',
            'method' => 'AVCO', 'scope' => 'COMPANY_ITEM', 'pool' => 'SKU-100', 'qty' => '10.0000',
            'uom' => 'EA', 'cost' => '12.5000', 'value' => '125.0000', 'base_value' => '125.0000',
            'currency' => 'PKR', 'fx' => '1.000000000', 'batch' => null, 'serial' => null,
            'effective' => '2026-09-01T00:00:00+05:00',
        ]], User::factory()->create());

        return [$profile, app(ValidateImportBatch::class)($batch)];
    }

    /** @return array<string, mixed> */
    private function cutoverInput(): array
    {
        return [
            'mode' => 'OPENING_STATE', 'cutover_at' => '2026-09-01T00:00:00+05:00',
            'source_system' => 'Legacy ERP', 'target_valuation_policy' => ['SKU-100' => ['method' => 'AVCO', 'scope' => 'COMPANY_ITEM']],
            'control_quantity' => '10.0000', 'control_value' => '125.0000', 'base_control_value' => '125.0000',
        ];
    }

    private function createInventoryReferences(): void
    {
        Currency::query()->create(['code' => 'PKR', 'name' => 'Pakistan Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        $uom = Uom::query()->create(['code' => 'EA', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1.000000000', 'is_base' => true]);
        $category = Category::query()->create(['code' => 'stock', 'name' => 'Stock', 'default_valuation_method' => ValuationMethod::Avco]);
        Item::query()->create([
            'code' => 'SKU-100', 'name' => 'Cutover item', 'type' => ItemType::Raw, 'stock_uom_id' => $uom->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'tracking' => ItemTracking::None,
            'category_id' => $category->id,
        ]);
        app(CreateWarehouse::class)([
            'code' => 'MAIN', 'name' => 'Main warehouse', 'is_default' => true,
            'location_code' => 'STOCK', 'location_name' => 'Stock',
        ]);
    }

    /** @param string|array<int, string> $permissions */
    private function permittedUser(string|array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }
}
