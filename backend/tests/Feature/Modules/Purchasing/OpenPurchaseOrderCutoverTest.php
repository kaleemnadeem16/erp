<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Imports\Actions\CreateImportProfile;
use App\Modules\Imports\Actions\StageImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Support\ImportPurpose;
use App\Modules\Inventory\Actions\ApproveInventoryCutover;
use App\Modules\Inventory\Actions\AttachInventoryCutoverBatch;
use App\Modules\Inventory\Actions\CommitInventoryCutoverOpeningState;
use App\Modules\Inventory\Actions\CreateInventoryCutover;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\FinaliseInventoryCutover;
use App\Modules\Inventory\Actions\ValidateInventoryCutover;
use App\Modules\Purchasing\Actions\CommitInventoryCutoverOpenPurchaseOrders;
use App\Modules\Purchasing\Imports\OpenPurchaseOrderImportTarget;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class OpenPurchaseOrderCutoverTest extends PurchaseOrderFoundationTest
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach ([
            'inventory.cutover.create', 'inventory.cutover.stage', 'inventory.cutover.validate',
            'inventory.cutover.approve', 'inventory.cutover.commit', 'inventory.cutover.finalise',
            'purchasing.cutover.commit',
        ] as $permission) {
            Permission::findOrCreate($permission);
        }
    }

    public function test_open_po_rows_materialise_as_issued_remaining_commitments_with_immutable_source_provenance(): void
    {
        [$draft, $item, $uom] = $this->makeOrder();
        $supplier = $draft->supplierAccount;
        $currency = $draft->currency;
        $draft->delete();
        $inventoryAccount = ChartOfAccount::create(['code' => 'cutover-inv', 'name' => 'Cutover inventory', 'type' => AccountType::Asset]);
        $item->update(['is_stocked' => true, 'inventory_account_id' => $inventoryAccount->id]);
        app(CreateWarehouse::class)([
            'code' => 'MAIN', 'name' => 'Main', 'is_default' => true,
            'location_code' => 'STOCK', 'location_name' => 'Stock',
        ]);

        $openingBatch = $this->validatedBatch('OPENING_STATE', [
            'source_record_id' => 'OPEN-1', 'item_code' => $item->code, 'warehouse_code' => 'MAIN',
            'location_code' => 'STOCK', 'source_valuation_method' => 'AVCO',
            'source_avco_scope' => 'COMPANY_ITEM', 'source_pool_identity' => $item->code,
            'quantity' => '1.0000', 'uom_code' => $uom->code, 'unit_cost' => '10.0000',
            'amount' => '10.0000', 'base_amount' => '10.0000', 'currency_code' => $currency->code,
            'fx_rate' => '1.000000000', 'batch_no' => null, 'serial_no' => null,
            'source_effective_at' => '2026-09-01T00:00:00+05:00',
        ]);
        $openPoBatch = $this->validatedBatch('OPEN_PURCHASE_ORDER', [
            'source_purchase_order_id' => 'LEGACY-PO-42', 'source_line_id' => 'LEGACY-PO-42-1',
            'supplier_code' => $supplier->code, 'supplier_site_code' => null,
            'currency_code' => $currency->code, 'fx_policy' => 'FIXED', 'fx_rate' => '1.000000000',
            'fx_rate_date' => '2026-08-15', 'fx_rate_type' => 'MIGRATED', 'fx_rate_source' => 'Legacy ERP',
            'order_date' => '2026-08-15', 'expected_delivery_date' => '2026-09-15',
            'line_number' => 1, 'item_code' => $item->code, 'description' => 'Remaining legacy commitment',
            'remaining_quantity' => '5.0000', 'purchase_uom_code' => $uom->code,
            'unit_price' => '12.0000', 'discount_amount' => '0.0000', 'tax_amount' => '0.0000',
            'line_expected_delivery_date' => '2026-09-15', 'qa_required' => false,
            'over_receipt_mode' => 'BLOCK', 'over_receipt_tolerance_percent' => '0.0000',
        ]);

        $cutover = app(CreateInventoryCutover::class)([
            'mode' => 'OPERATIONAL', 'cutover_at' => '2026-09-01T00:00:00+05:00',
            'source_system' => 'Legacy ERP',
            'target_valuation_policy' => [$item->code => ['method' => 'AVCO', 'scope' => 'COMPANY_ITEM']],
            'control_quantity' => '1.0000', 'control_value' => '10.0000', 'base_control_value' => '10.0000',
        ], $this->permittedUser('inventory.cutover.create'));
        app(AttachInventoryCutoverBatch::class)($cutover, $openingBatch, $this->permittedUser('inventory.cutover.stage'));
        $openPoLink = app(AttachInventoryCutoverBatch::class)($cutover, $openPoBatch, $this->permittedUser('inventory.cutover.stage'));
        $cutover = app(ValidateInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.validate'));
        $cutover = app(ApproveInventoryCutover::class)($cutover, $this->permittedUser('inventory.cutover.approve'));
        app(CommitInventoryCutoverOpeningState::class)($cutover, $this->permittedUser('inventory.cutover.commit'));

        $committer = $this->permittedUser('purchasing.cutover.commit');
        $this->actingAs($committer)->postJson("/api/v1/inventory-cutovers/{$cutover->id}/commit-open-purchase-orders")
            ->assertOk()->assertJsonPath('data.0.source_record_id', 'LEGACY-PO-42');
        $retry = app(CommitInventoryCutoverOpenPurchaseOrders::class)($cutover->refresh(), $committer);

        $order = PurchaseOrder::query()->with('lines')->sole();
        $this->assertSame($order->id, $retry->sole()->id);
        $this->assertSame('ISSUED', $order->status->value);
        $this->assertSame($cutover->id, $order->inventory_cutover_id);
        $this->assertSame($openPoBatch->id, $order->import_batch_id);
        $this->assertSame('LEGACY-PO-42', $order->source_record_id);
        $this->assertSame('5.0000', $order->lines->sole()->ordered_qty);
        $this->assertSame('LEGACY-PO-42-1', $order->lines->sole()->source_record_id);
        $this->assertSame($openPoBatch->rows->sole()->id, $order->lines->sole()->import_batch_row_id);
        $this->assertSame('COMMITTED', $openPoLink->refresh()->status->value);

        $finalised = app(FinaliseInventoryCutover::class)($cutover->refresh(), $this->permittedUser('inventory.cutover.finalise'));
        $this->assertSame('FINALISED', $finalised->status->value);
    }

    public function test_remaining_quantity_cannot_be_invented_by_an_approved_default(): void
    {
        $approver = $this->permittedUser('inventory.cutover.approve');
        $this->expectException(DomainInvariantException::class);

        app(CreateImportProfile::class)(
            'Unsafe open PO', ImportPurpose::InventoryCutover, 'OPEN_PURCHASE_ORDER', $this->mappings('OPEN_PURCHASE_ORDER'),
            ['remaining_quantity' => ['type' => 'DEFAULT_IF_MISSING', 'value' => '1.0000', 'reason' => 'Unknown open quantity']],
            $approver,
        );
    }

    /** @param array<string, mixed> $row */
    private function validatedBatch(string $entityType, array $row): ImportBatch
    {
        $profile = app(CreateImportProfile::class)(
            "{$entityType} cutover", ImportPurpose::InventoryCutover, $entityType, $this->mappings($entityType),
        );
        $batch = app(StageImportBatch::class)(
            $profile, ImportPurpose::InventoryCutover, strtolower($entityType).'-'.uniqid(), 'cutover.csv', [$row], User::factory()->create(),
        );

        return app(ValidateImportBatch::class)($batch);
    }

    /** @return array<string, string> */
    private function mappings(string $entityType): array
    {
        $fields = $entityType === 'OPEN_PURCHASE_ORDER'
            ? array_keys(app(OpenPurchaseOrderImportTarget::class)->rules())
            : [
                'source_record_id', 'item_code', 'warehouse_code', 'location_code', 'source_valuation_method',
                'source_avco_scope', 'source_pool_identity', 'quantity', 'uom_code', 'unit_cost', 'amount',
                'base_amount', 'currency_code', 'fx_rate', 'batch_no', 'serial_no', 'source_effective_at',
            ];

        return array_combine($fields, $fields);
    }

    private function permittedUser(string $permission): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $user;
    }
}
