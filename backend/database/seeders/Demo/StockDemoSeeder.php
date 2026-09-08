<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Imports\Actions\CreateImportProfile;
use App\Modules\Imports\Actions\StageImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Support\ImportPurpose;
use App\Modules\Inventory\Actions\AddPhysicalCountLine;
use App\Modules\Inventory\Actions\ApproveInventoryCutover;
use App\Modules\Inventory\Actions\AttachInventoryCutoverBatch;
use App\Modules\Inventory\Actions\CreateInventoryCutover;
use App\Modules\Inventory\Actions\CreatePhysicalCount;
use App\Modules\Inventory\Actions\PostPhysicalCount;
use App\Modules\Inventory\Actions\RecordPhysicalCountLine;
use App\Modules\Inventory\Actions\ValidateInventoryCutover;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceipt;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Populates the stock ledger, physical counts and inventory cutover screens
 * with real data produced through the same Actions the app itself uses —
 * goods receipts, physical count posting, and one inventory cutover left at
 * APPROVED so the demo login can complete Commit/Finalise itself in the UI.
 *
 * Two throwaway actor accounts (never documented as logins) exist purely to
 * satisfy separation-of-duties rules (a physical count's poster and an
 * inventory cutover's approver must differ from its creator/validator) —
 * exactly the kind of multi-person workflow this data is meant to show off.
 * The demo login itself is granted every permission these screens gate on,
 * so it can drive the remaining actions after seeding.
 */
final class StockDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach ([
            'inventory.physical_count.create', 'inventory.physical_count.count', 'inventory.physical_count.post',
            'inventory.cutover.create', 'inventory.cutover.stage', 'inventory.cutover.validate', 'inventory.cutover.approve',
            'inventory.cutover.commit', 'inventory.cutover.finalise',
            'inventory.cutover.negative_opening.request', 'inventory.cutover.negative_opening.approve',
        ] as $permission) {
            Permission::findOrCreate($permission);
        }

        $demoUser = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $demoUser->syncPermissions([
            'inventory.physical_count.create', 'inventory.physical_count.count', 'inventory.physical_count.post',
            'inventory.cutover.create', 'inventory.cutover.stage', 'inventory.cutover.validate', 'inventory.cutover.approve',
            'inventory.cutover.commit', 'inventory.cutover.finalise',
            'inventory.cutover.negative_opening.request', 'inventory.cutover.negative_opening.approve',
        ]);

        $ops = $this->seedActor('demo-ops@erp.x1techs.com', 'Demo Ops Assistant', [
            'inventory.physical_count.create', 'inventory.physical_count.count',
            'inventory.cutover.create', 'inventory.cutover.stage', 'inventory.cutover.validate',
        ]);
        $supervisor = $this->seedActor('demo-supervisor@erp.x1techs.com', 'Demo Inventory Supervisor', [
            'inventory.cutover.approve',
        ]);

        $warehouse = Warehouse::query()->where('code', 'demo-main')->firstOrFail();
        $location = $warehouse->locations()->where('code', 'stock')->firstOrFail();
        $currency = Currency::query()->where('code', 'PKR')->firstOrFail();

        $this->seedGoodsReceipts($location, $currency);
        $this->seedPhysicalCounts($warehouse, $location, $ops, $demoUser);
        $this->seedInventoryCutover($location, $currency, $ops, $supervisor);
    }

    /** @param  list<string>  $permissions */
    private function seedActor(string $email, string $name, array $permissions): User
    {
        $user = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => (string) str()->random(40)]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->syncPermissions($permissions);

        return $user;
    }

    private function seedGoodsReceipts(Location $location, Currency $currency): void
    {
        $item = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', 'sup-punjab')->firstOrFail();

        foreach ([
            ['key' => 'demo-receipt-pigblue-1', 'qty' => '150.0000', 'cost' => '480.0000', 'daysAgo' => 20],
            ['key' => 'demo-receipt-pigblue-2', 'qty' => '80.0000', 'cost' => '495.0000', 'daysAgo' => 6],
        ] as $data) {
            if (GoodsReceipt::query()->where('idempotency_key', $data['key'])->exists()) {
                continue;
            }

            $orderedAt = now()->subDays($data['daysAgo'])->format('Y-m-d');
            $order = app(CreatePurchaseOrder::class)([
                'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
                'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
                'order_date' => $orderedAt,
                'lines' => [[
                    'line_number' => 1, 'item_id' => $item->getKey(), 'description' => $item->name,
                    'ordered_qty' => $data['qty'], 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => $data['cost'],
                ]],
            ]);
            $order = app(IssuePurchaseOrder::class)($order);
            $line = $order->lines->sole();

            $receipt = app(CreateGoodsReceipt::class)([
                'supplier_account_id' => $supplier->getKey(),
                'physical_arrival_at' => now()->subDays($data['daysAgo'])->toIso8601String(),
                'posting_date' => $orderedAt,
                'lines' => [[
                    'purchase_order_line_id' => $line->getKey(), 'item_id' => $item->getKey(), 'location_id' => $location->getKey(),
                    'received_qty' => $data['qty'], 'received_uom_id' => $item->stock_uom_id, 'provisional_unit_cost' => $data['cost'],
                    'currency_id' => $currency->getKey(), 'fx_rate' => '1.000000000',
                    'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
                ]],
            ], $data['key']);
            app(PostGoodsReceipt::class)($receipt);
        }
    }

    private function seedPhysicalCounts(Warehouse $warehouse, Location $location, User $creator, User $poster): void
    {
        $item = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();

        if (! PhysicalCount::query()->where('reference', 'DEMO-PC-001')->exists()) {
            $count = app(CreatePhysicalCount::class)(['warehouse_id' => $warehouse->getKey(), 'reference' => 'DEMO-PC-001'], $creator);
            $line = app(AddPhysicalCountLine::class)($count, ['item_id' => $item->getKey(), 'location_id' => $location->getKey()], $creator);
            // Slightly under book — a routine shrinkage write-off, no found cost needed.
            app(RecordPhysicalCountLine::class)($line, '224.0000', $creator);
            app(PostPhysicalCount::class)($count, [], $poster);
        }

        if (! PhysicalCount::query()->where('reference', 'DEMO-PC-002')->exists()) {
            $count = app(CreatePhysicalCount::class)(['warehouse_id' => $warehouse->getKey(), 'reference' => 'DEMO-PC-002'], $creator);
            // Left uncounted deliberately — the demo login can record and post it.
            app(AddPhysicalCountLine::class)($count, ['item_id' => $item->getKey(), 'location_id' => $location->getKey()], $creator);
        }
    }

    private function seedInventoryCutover(Location $location, Currency $currency, User $creator, User $approver): void
    {
        if (InventoryCutover::query()->exists()) {
            return;
        }

        $item = Item::query()->where('code', 'demo-thinner')->firstOrFail();
        $cutoverAt = now()->subDays(45);

        $profile = app(CreateImportProfile::class)('Demo legacy stock close', ImportPurpose::InventoryCutover, 'OPENING_STATE', [
            'source_id' => 'source_record_id', 'sku' => 'item_code', 'warehouse' => 'warehouse_code',
            'location' => 'location_code', 'method' => 'source_valuation_method', 'scope' => 'source_avco_scope',
            'pool' => 'source_pool_identity', 'qty' => 'quantity', 'uom' => 'uom_code', 'cost' => 'unit_cost',
            'value' => 'amount', 'base_value' => 'base_amount', 'currency' => 'currency_code', 'fx' => 'fx_rate',
            'batch' => 'batch_no', 'serial' => 'serial_no', 'effective' => 'source_effective_at',
        ]);
        $row = [
            'source_id' => 'LEGACY-THINNER-OPEN', 'sku' => $item->code, 'warehouse' => 'demo-main', 'location' => 'stock',
            'method' => 'FIFO', 'scope' => null, 'pool' => null, 'qty' => '200.0000',
            'uom' => 'l', 'cost' => '350.0000', 'value' => '70000.0000', 'base_value' => '70000.0000',
            'currency' => $currency->code, 'fx' => '1.000000000', 'batch' => null, 'serial' => null, 'effective' => null,
        ];
        $batch = app(StageImportBatch::class)($profile, ImportPurpose::InventoryCutover, 'demo-cutover-thinner-open', 'legacy_stock.csv', [$row], $creator);
        $batch = app(ValidateImportBatch::class)($batch);

        $cutover = app(CreateInventoryCutover::class)([
            'mode' => 'OPENING_STATE', 'cutover_at' => $cutoverAt->toIso8601String(),
            'source_system' => 'Legacy ERP (Demo)',
            'target_valuation_policy' => [$item->code => ['method' => 'FIFO']],
            'control_quantity' => '200.0000', 'control_value' => '70000.0000', 'base_control_value' => '70000.0000',
        ], $creator);
        app(AttachInventoryCutoverBatch::class)($cutover, $batch, $creator);
        app(ValidateInventoryCutover::class)($cutover->refresh(), $creator);
        // Left APPROVED — separation of duties needs a third actor for
        // Commit/Finalise no more than it needs one for this step, so the
        // demo login (already granted both permissions) can finish it live.
        app(ApproveInventoryCutover::class)($cutover->refresh(), $approver);
    }
}
