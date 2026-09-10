<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Imports\Actions\CreateImportProfile;
use App\Modules\Imports\Actions\StageImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Support\ImportPurpose;
use App\Modules\Inventory\Actions\AddPhysicalCountLine;
use App\Modules\Inventory\Actions\ApproveInventoryCutover;
use App\Modules\Inventory\Actions\AttachInventoryCutoverBatch;
use App\Modules\Inventory\Actions\CreateInventoryCutover;
use App\Modules\Inventory\Actions\CreatePhysicalCount;
use App\Modules\Inventory\Actions\DecideRecalculationApproval;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\PostPhysicalCount;
use App\Modules\Inventory\Actions\RecordPhysicalCountLine;
use App\Modules\Inventory\Actions\RequestNegativeStockOverride;
use App\Modules\Inventory\Actions\RequestStockRecalculation;
use App\Modules\Inventory\Actions\ValidateInventoryCutover;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Populates the stock ledger, physical counts, inventory cutover,
 * negative-stock override and stock recalculation screens with real data
 * produced through the same Actions the app itself uses — goods receipts,
 * physical count posting, an inventory cutover left at APPROVED, two
 * override requests (one per authorisation mode) left undecided/unreviewed,
 * and a decided-but-unexecuted recalculation request — so the demo login
 * can complete the remaining step of each workflow itself in the UI.
 *
 * Two throwaway actor accounts (never documented as logins) exist purely to
 * satisfy separation-of-duties rules (a physical count's poster, an
 * inventory cutover's approver, a negative-stock override's decider/
 * reviewer, and a recalculation's approver must differ from the request's
 * own creator) — exactly the kind of multi-person workflow this data is
 * meant to show off. The demo login itself is granted every permission
 * these screens gate on, so it can drive the remaining actions after
 * seeding.
 */
final class StockDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    /**
     * Every permission the demo login is granted, in one place — the single
     * source of truth `syncPermissions` below reads from, so re-running this
     * seeder can't silently narrow what the demo login can do. Includes the
     * settings/policy-history permissions Part A's screens gate on, which
     * used to be granted ad hoc outside the seeder and would otherwise be
     * wiped by the `syncPermissions` call below on the next re-seed.
     *
     * @var list<string>
     */
    private const DEMO_USER_PERMISSIONS = [
        'inventory.physical_count.create', 'inventory.physical_count.count', 'inventory.physical_count.post',
        'inventory.cutover.create', 'inventory.cutover.stage', 'inventory.cutover.validate', 'inventory.cutover.approve',
        'inventory.cutover.commit', 'inventory.cutover.finalise',
        'inventory.cutover.negative_opening.request', 'inventory.cutover.negative_opening.approve',
        'inventory.negative-stock.request', 'inventory.negative-stock.override',
        'inventory.negative-stock.approve', 'inventory.negative-stock.review',
        'inventory.recalculation.request', 'inventory.recalculation.approve',
        'inventory.recalculation.approve-high-risk', 'inventory.recalculation.execute',
        'inventory.recalculation.review-regeneration',
        'purchasing.over-receipt-policy.manage', 'purchasing.policy-history.view',
        'inventory.location-policy.manage', 'inventory.valuation-policy.manage', 'inventory.policy-history.view',
    ];

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (self::DEMO_USER_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
        }

        $demoUser = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $demoUser->syncPermissions(self::DEMO_USER_PERMISSIONS);

        $ops = $this->seedActor('demo-ops@erp.x1techs.com', 'Demo Ops Assistant', [
            'inventory.physical_count.create', 'inventory.physical_count.count',
            'inventory.cutover.create', 'inventory.cutover.stage', 'inventory.cutover.validate',
            'inventory.negative-stock.request', 'inventory.negative-stock.override',
            'inventory.recalculation.request',
        ]);
        $supervisor = $this->seedActor('demo-supervisor@erp.x1techs.com', 'Demo Inventory Supervisor', [
            'inventory.cutover.approve', 'inventory.recalculation.approve',
        ]);

        $warehouse = Warehouse::query()->where('code', 'demo-main')->firstOrFail();
        $location = $warehouse->locations()->where('code', 'stock')->firstOrFail();
        $currency = Currency::query()->where('code', 'PKR')->firstOrFail();

        $this->seedGoodsReceipts($location, $currency);
        $this->seedInventoryOverviewDemoData($location, $currency, $demoUser);
        $this->seedPhysicalCounts($warehouse, $location, $ops, $demoUser);
        $this->seedInventoryCutover($location, $currency, $ops, $supervisor);
        $this->seedNegativeStockOverrides($location, $ops);
        $this->seedRecalculationRequest($location, $currency, $ops, $supervisor);
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

        // This item can already carry earlier stock movements in a long-lived dev
        // database (manually posted test moves, predating this seeder's own run) —
        // a receipt dated before the latest one is rejected as backdated. Anchor
        // relative to that latest move instead of a fixed "days ago" so this stays
        // safe to run against both a fresh database and an already-populated one.
        $latestExistingMove = StockMove::query()->where('item_id', $item->getKey())->max('moved_at');
        $earliestSafeDate = $latestExistingMove !== null
            ? Carbon::parse($latestExistingMove)->addDay()->min(now())
            : now()->subDays(20);

        foreach ([
            ['key' => 'demo-receipt-pigblue-1', 'qty' => '150.0000', 'cost' => '480.0000', 'movedAt' => $earliestSafeDate->copy()],
            ['key' => 'demo-receipt-pigblue-2', 'qty' => '80.0000', 'cost' => '495.0000', 'movedAt' => $earliestSafeDate->copy()->addDays(3)->min(now())],
        ] as $data) {
            if (GoodsReceipt::query()->where('idempotency_key', $data['key'])->exists()) {
                continue;
            }

            $orderedAt = $data['movedAt']->format('Y-m-d');
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
                'physical_arrival_at' => $data['movedAt']->toIso8601String(),
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

    /**
     * Beyond demo-pig-blue's two receipts above, gives every other stocked
     * demo item a real on-hand quantity, plus a QA-held split and an
     * outstanding (issued, unreceived) PO line — so the Inventory Overview
     * screen (on-hand/available/QA-held/on-order/shortage) has real, varied
     * numbers instead of a column of zeros. All through the same real
     * Actions as seedGoodsReceipts above, never raw inventory-table writes.
     * Shortage is deliberately left at 0 here: seedNegativeStockOverrides
     * below already leaves two real override requests pending decision, so
     * the demo login can produce a real shortage itself by approving one
     * rather than one being pre-baked.
     */
    private function seedInventoryOverviewDemoData(Location $location, Currency $currency, User $demoUser): void
    {
        $this->seedPlainReceipt($location, $currency, 'demo-pig-red', 'sup-punjab', 'demo-receipt-pigred-1', '100.0000', '520.0000', 10, 'PR-OPEN-01');
        $this->seedPlainReceipt($location, $currency, 'demo-thinner', 'sup-pakchem', 'demo-receipt-thinner-1', '60.0000', '340.0000', 8, null);
        $this->seedPlainReceipt($location, $currency, 'demo-paint-white', 'sup-coatings', 'demo-receipt-paintwhite-1', '40.0000', '1200.0000', 5, 'PW-OPEN-01');
        $this->seedSerialisedReceipts($location, $currency);
        $this->seedQaHeldReceipt($location, $currency, $demoUser);
        $this->seedOutstandingPurchaseOrder($currency);
    }

    /** A single-line PO, issued and fully received in one go — the same shape as seedGoodsReceipts' loop, parametrised for reuse across the remaining items. */
    private function seedPlainReceipt(
        Location $location, Currency $currency, string $itemCode, string $supplierCode,
        string $idempotencyKey, string $qty, string $cost, int $daysAgo, ?string $batchNo
    ): void {
        if (GoodsReceipt::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        $item = Item::query()->where('code', $itemCode)->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', $supplierCode)->firstOrFail();
        $orderedAt = now()->subDays($daysAgo)->format('Y-m-d');

        $order = app(CreatePurchaseOrder::class)([
            'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
            'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => $orderedAt,
            'lines' => [[
                'line_number' => 1, 'item_id' => $item->getKey(), 'description' => $item->name,
                'ordered_qty' => $qty, 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => $cost,
            ]],
        ]);
        $order = app(IssuePurchaseOrder::class)($order);
        $line = $order->lines->sole();

        $receipt = app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $supplier->getKey(),
            'physical_arrival_at' => now()->subDays($daysAgo)->toIso8601String(),
            'posting_date' => $orderedAt,
            'lines' => [[
                'purchase_order_line_id' => $line->getKey(), 'item_id' => $item->getKey(), 'location_id' => $location->getKey(),
                'received_qty' => $qty, 'received_uom_id' => $item->stock_uom_id, 'provisional_unit_cost' => $cost,
                'currency_id' => $currency->getKey(), 'fx_rate' => '1.000000000',
                'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
                'batch_no' => $batchNo,
            ]],
        ], $idempotencyKey);
        app(PostGoodsReceipt::class)($receipt);
    }

    /** demo-spray-unit is SERIAL-tracked (and SPECIFIC-valued): each receipt line is exactly one unit with its own serial. */
    private function seedSerialisedReceipts(Location $location, Currency $currency): void
    {
        $item = Item::query()->where('code', 'demo-spray-unit')->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', 'sup-coatings')->firstOrFail();

        foreach ([
            ['key' => 'demo-receipt-sprayunit-1', 'serial' => 'SU-1001', 'daysAgo' => 12],
            ['key' => 'demo-receipt-sprayunit-2', 'serial' => 'SU-1002', 'daysAgo' => 9],
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
                    'ordered_qty' => '1.0000', 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => '45000.0000',
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
                    'received_qty' => '1.0000', 'received_uom_id' => $item->stock_uom_id, 'provisional_unit_cost' => '45000.0000',
                    'currency_id' => $currency->getKey(), 'fx_rate' => '1.000000000',
                    'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
                    'serial_no' => $data['serial'],
                ]],
            ], $data['key']);
            app(PostGoodsReceipt::class)($receipt);
        }
    }

    /**
     * A second, QA-required demo-pig-red receipt, left with a partial PASS
     * disposition — 30 of 50 released, 20 still QA-held — so the demo login
     * can resolve the remaining branch live via the Goods Receipt detail
     * screen instead of every QA case arriving pre-resolved.
     */
    private function seedQaHeldReceipt(Location $location, Currency $currency, User $demoUser): void
    {
        $key = 'demo-receipt-pigred-qa';
        if (GoodsReceipt::query()->where('idempotency_key', $key)->exists()) {
            return;
        }

        $item = Item::query()->where('code', 'demo-pig-red')->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', 'sup-punjab')->firstOrFail();
        $orderedAt = now()->subDays(4)->format('Y-m-d');

        $order = app(CreatePurchaseOrder::class)([
            'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
            'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => $orderedAt,
            'lines' => [[
                'line_number' => 1, 'item_id' => $item->getKey(), 'description' => $item->name,
                'ordered_qty' => '50.0000', 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => '540.0000',
                'qa_required' => true,
            ]],
        ]);
        $order = app(IssuePurchaseOrder::class)($order);
        $line = $order->lines->sole();

        $receipt = app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $supplier->getKey(),
            'physical_arrival_at' => now()->subDays(4)->toIso8601String(),
            'posting_date' => $orderedAt,
            'lines' => [[
                'purchase_order_line_id' => $line->getKey(), 'item_id' => $item->getKey(), 'location_id' => $location->getKey(),
                'received_qty' => '50.0000', 'received_uom_id' => $item->stock_uom_id, 'provisional_unit_cost' => '540.0000',
                'currency_id' => $currency->getKey(), 'fx_rate' => '1.000000000',
                'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
                'batch_no' => 'PR-QA-01',
            ]],
        ], $key);
        app(PostGoodsReceipt::class)($receipt);

        app(PostQualityDisposition::class)(
            $receipt->lines->sole(),
            ['disposition' => 'PASS', 'qty' => '30.0000', 'reason' => 'Demo: partial QA pass, remainder pending inspection.'],
            $demoUser->getKey(),
        );
    }

    /**
     * Deliberately left ISSUED and unreceived — an outstanding order the
     * demo login can receive live, and the source of demo-pig-blue's
     * on_order_qty on the Inventory Overview screen.
     */
    private function seedOutstandingPurchaseOrder(Currency $currency): void
    {
        $item = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', 'sup-pakchem')->firstOrFail();

        $alreadySeeded = PurchaseOrder::query()->where('supplier_account_id', $supplier->getKey())
            ->whereHas('lines', fn ($query) => $query->where('item_id', $item->getKey())->where('ordered_qty', '75.0000'))
            ->exists();
        if ($alreadySeeded) {
            return;
        }

        $orderedAt = now()->subDays(2)->format('Y-m-d');
        $order = app(CreatePurchaseOrder::class)([
            'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
            'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => $orderedAt,
            'lines' => [[
                'line_number' => 1, 'item_id' => $item->getKey(), 'description' => $item->name,
                'ordered_qty' => '75.0000', 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => '505.0000',
            ]],
        ]);
        app(IssuePurchaseOrder::class)($order);
    }

    private function seedPhysicalCounts(Warehouse $warehouse, Location $location, User $creator, User $poster): void
    {
        $item = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();

        if (! PhysicalCount::query()->where('reference', 'DEMO-PC-001')->exists()) {
            // Slightly under book by a fixed 6 units — a routine shrinkage write-off,
            // no found cost needed. Derived from live book qty (not hardcoded) so it
            // stays a small, routine-looking variance regardless of how much stock
            // other seeded/manual receipts have already put on hand for this item.
            $bookQty = (float) StockLayer::query()
                ->where('item_id', $item->getKey())->where('location_id', $location->getKey())
                ->whereNull('batch_id')->whereNull('serial_no')->sum('qty_remaining');
            $count = app(CreatePhysicalCount::class)(['warehouse_id' => $warehouse->getKey(), 'reference' => 'DEMO-PC-001'], $creator);
            $line = app(AddPhysicalCountLine::class)($count, ['item_id' => $item->getKey(), 'location_id' => $location->getKey()], $creator);
            app(RecordPhysicalCountLine::class)($line, number_format(max($bookQty - 6, 0), 4, '.', ''), $creator);
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

    /**
     * One request per authorisation mode, both left at their one
     * outstanding step (undecided / unreviewed) so the demo login can
     * finish either live. authorisation_mode is snapshotted per-request
     * from the company's setting at request time, so flipping company
     * policy between the two calls is what produces one of each — company
     * policy itself is left on IMMEDIATE_WITH_REVIEW afterwards, since
     * that's the more permissive mode to explore new requests under.
     */
    private function seedNegativeStockOverrides(Location $location, User $requester): void
    {
        $item = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();
        $company = Company::query()->findOrFail(1);
        $company->update(['negative_stock_mode' => 'AUTHORISED_OVERRIDE']);

        // The Action derives availability from live stock, so each demo
        // request has to ask for genuinely more than is on hand — derived
        // here rather than hardcoded so changing the seeded receipts above
        // cannot silently make these requests impossible.
        $onHand = (float) StockLayer::query()
            ->where('item_id', $item->getKey())->where('location_id', $location->getKey())
            ->whereNull('batch_id')->whereNull('serial_no')->sum('qty_remaining');

        $company->update(['negative_stock_authorisation_mode' => 'PRIOR_APPROVAL']);
        app(RequestNegativeStockOverride::class)([
            'item_id' => $item->getKey(), 'location_id' => $location->getKey(), 'movement_class' => 'MATERIAL_CONSUMPTION',
            'requested_qty' => number_format($onHand + 30, 4, '.', ''), 'source_type' => 'DEMO_JOB_ISSUE', 'source_id' => 1,
            'reason_code' => 'OPERATIONAL_EMERGENCY', 'explanation' => 'Urgent job material issue ahead of the delayed weekly delivery.',
        ], $requester);

        $company->update(['negative_stock_authorisation_mode' => 'IMMEDIATE_WITH_REVIEW']);
        app(RequestNegativeStockOverride::class)([
            'item_id' => $item->getKey(), 'location_id' => $location->getKey(), 'movement_class' => 'MATERIAL_CONSUMPTION',
            'requested_qty' => number_format($onHand + 15, 4, '.', ''), 'source_type' => 'DEMO_JOB_ISSUE', 'source_id' => 2,
            'reason_code' => 'PAPERWORK_DELAY', 'explanation' => 'Supplier delivery note is delayed; stock physically received.',
        ], $requester);
    }

    /**
     * A dedicated item, not touched by any other seeded workflow: a
     * recalculation targets one specific historical movement, so reusing an
     * item already carrying receipts/counts/a cutover would tangle this
     * fixture's dates with theirs for no real benefit. NORMAL_RISK only
     * (no company threshold set) — a HIGH_RISK/paused-regeneration scenario
     * needs three repeated ledger invalidations to construct and isn't
     * worth the fixture complexity for demo purposes alone. Decided but
     * left unexecuted so the demo login can execute it live.
     */
    private function seedRecalculationRequest(Location $location, Currency $currency, User $requester, User $approver): void
    {
        if (RecalculationRequest::query()->exists()) {
            return;
        }

        $company = Company::query()->findOrFail(1);
        $company->update([
            'authorised_forward_recalculation_enabled' => true,
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => now()->subYear()->toDateString(),
        ]);

        $uom = Uom::query()->where('code', 'l')->firstOrFail();
        $category = Category::query()->where('code', 'demo-solvents')->firstOrFail();
        $item = Item::withTrashed()->updateOrCreate(
            ['code' => 'demo-recalc-thinner'],
            [
                'name' => 'Recalc Demo Thinner', 'description' => 'Dedicated item for the stock recalculation demo.',
                'type' => ItemType::Raw, 'stock_uom_id' => $uom->id, 'is_stocked' => true, 'is_purchasable' => false, 'is_sellable' => false,
                'valuation_method' => ValuationMethod::Avco, 'avco_pool_scope' => AvcoPoolScope::CompanyItem, 'tracking' => ItemTracking::None,
                'category_id' => $category->id, 'inventory_account_id' => null, 'custom_fields' => [],
            ],
        );
        $item->restore();
        if ($item->resolveInventoryAccountId() === null) {
            $item->update(['inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id]);
        }

        // Receipt 40@100 a month ago, an issue of 10 two weeks ago — the
        // later movement a backdated receipt three weeks ago conflicts with.
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '40.0000', 'uom_id' => $uom->id,
            'moved_at' => now()->subDays(30), 'source_type' => 'DEMO_RECALC_RECEIPT', 'source_id' => 1,
            'unit_cost' => '100.0000', 'currency_id' => $currency->id, 'fx_rate' => '1.000000000', 'base_unit_cost' => '100.0000',
        ]);
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '10.0000', 'uom_id' => $uom->id,
            'moved_at' => now()->subDays(14), 'source_type' => 'DEMO_RECALC_ISSUE', 'source_id' => 1,
        ]);

        $request = app(RequestStockRecalculation::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '20.0000',
            'unit_cost' => '120.0000', 'currency_id' => $currency->id, 'fx_rate' => '1.000000000', 'base_unit_cost' => '120.0000',
            'moved_at' => now()->subDays(21), 'source_type' => 'DEMO_RECALC_BACKDATED_RECEIPT', 'source_id' => 1,
        ], $requester);

        app(DecideRecalculationApproval::class)(
            $request->impactVersions->sole(), RecalculationApprovalDecision::Approved,
            'Late paperwork confirmed against the supplier invoice.', $approver,
        );
    }
}
