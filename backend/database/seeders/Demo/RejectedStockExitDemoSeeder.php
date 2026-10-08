<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\Role;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Administration\Actions\SyncMemberAccess;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Inventory\Actions\ActivateGroupedPackageTracking;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Actions\CreateStockScrap;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\PostStockScrap;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\CreateSupplierReturn;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Actions\PostSupplierReturn;
use App\Modules\Purchasing\Actions\RecordReceiptComposition;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Support\CompanyContext;
use App\Support\PermissionRegistry;
use Database\Seeders\Demo\Concerns\CreatesDemoRolePersonas;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Rejected-stock exit (ADR 0097), XYZ Paints. Grouped emulsion: an inspection-required delivery of 3 sealed 5 L cans + 4 L loose (19 L) is decided by QA —
 * one can passed, one can rejected for return, one can + 4 L loose rejected for scrap. One **Supplier Return** posts the rejected can; one **Scrap** posts
 * a can and 2 L of the loose, leaving 2 L loose rejected awaiting scrap. A quantity-only delivery leaves 4 L rejected with a **draft** Supplier Return
 * waiting to be posted. Also three people: a preparer (drafts only), a controller (prepares and posts) and a site clerk limited to their own units.
 * Created once through the real Actions; a rerun never recomputes history or disturbs later manual progress.
 */
final class RejectedStockExitDemoSeeder extends Seeder
{
    use CreatesDemoRolePersonas;
    use EnsuresDemoEnvironment;

    public const PREPARER = 'demo-exit-preparer@erp.x1techs.com';

    public const CONTROLLER = 'demo-exit-controller@erp.x1techs.com';

    public const SITE_CLERK = 'demo-exit-clerk@erp.x1techs.com';

    private const READS = ['purchasing.goods-receipt.view', 'inventory.package-state.view', 'inventory.stock.view'];

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(1);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $actor->givePermissionTo(['inventory.package-state.view', 'inventory.package-state.activate', 'inventory.physical_count.create', 'purchasing.quality-disposition.post',
            'purchasing.supplier-return.create', 'purchasing.supplier-return.post', 'inventory.scrap.create', 'inventory.scrap.post']);
        $this->personas($actor);
        if (PackageOperation::query()->where('request_key', 'demo-xyz-exit-count')->exists()) {
            return; // Immutable history and later manual progress are preserved.
        }
        DB::transaction(function () use ($actor): void {
            $this->groupedScenario($actor);
            $this->quantityOnlyScenario($actor);
        });
    }

    private function personas(User $admin): void
    {
        $prepare = ['purchasing.supplier-return.create', 'inventory.scrap.create'];
        $post = ['purchasing.supplier-return.post', 'inventory.scrap.post'];
        $this->rolePersona($admin, 'Rejected-stock preparer', 'Drafts Supplier Returns and Scrap for rejected stock; someone else posts them.',
            [...self::READS, ...$prepare], self::PREPARER, 'Demo Exit Preparer');
        $this->rolePersona($admin, 'Rejected-stock controller', 'Prepares and posts Supplier Returns and Scrap, and sees the stock value.',
            [...self::READS, ...$prepare, ...$post, 'inventory.valuation.view'], self::CONTROLLER, 'Demo Exit Controller');
        $this->rolePersona($admin, 'Site returns clerk', 'Prepares and posts Supplier Returns and Scrap, limited to the warehouses of their own organisation units.',
            [...self::READS, ...$prepare, ...$post], self::SITE_CLERK, 'Demo Exit Site Clerk');
        $clerk = User::query()->where('email', self::SITE_CLERK)->firstOrFail();
        $role = Role::query()->where('team_id', app(CompanyContext::class)->id())->where('name', 'Site returns clerk')->firstOrFail();
        if (! AccessScopeLimit::query()->where('user_id', $clerk->id)->exists()) {
            app(SyncMemberAccess::class)($clerk, ['role_ids' => [$role->id], 'direct_permissions' => [],
                'scope_limits' => [['role_id' => $role->id, 'scope' => AccessScopeLimit::MY_UNITS]], 'reason' => 'Demo: the clerk reaches only their own site.'], $admin);
        }
    }

    private function item(User $actor, string $code, string $name): Item
    {
        $base = Item::query()->where('code', 'demo-pack-thinner')->firstOrFail();
        $unit = Uom::query()->where('code', 'l')->firstOrFail();

        return Item::withTrashed()->where('code', $code)->first()
            ?? app(CreateItem::class)(['code' => $code, 'name' => $name, 'type' => 'RAW', 'stock_uom_id' => $unit->id, 'category_id' => $base->category_id,
                'valuation_method' => 'FIFO', 'tracking' => 'NONE', 'inventory_account_id' => $base->inventory_account_id, 'is_stocked' => true, 'is_purchasable' => true, 'is_sellable' => false], $actor);
    }

    /**
     * A posted QA-required delivery of the item; returns the receipt's single line.
     *
     * @param  list<array<string,mixed>>|null  $composition
     */
    private function delivery(User $actor, Item $item, string $quantity, string $note, string $key, ?array $composition = null, ?ItemPackaging $can = null): GoodsReceiptLine
    {
        $supplier = SupplierAccount::query()->where('code', 'sup-pakchem')->firstOrFail();
        $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
        $unit = Uom::query()->where('code', 'l')->firstOrFail();
        $location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
        $order = app(CreatePurchaseOrder::class)([
            'supplier_account_id' => $supplier->id, 'currency_id' => $currency->id, 'fx_rate' => '1.000000000', 'fx_rate_date' => now()->toDateString(),
            'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => now()->toDateString(), 'notes' => $note,
            'lines' => [['line_number' => 1, 'item_id' => $item->id, 'description' => $item->name, 'ordered_qty' => $quantity, 'purchase_uom_id' => $unit->id,
                'unit_price' => '10', 'price_uom_id' => $unit->id, 'price_quantity' => '1', 'qa_required' => true]],
        ], $actor);
        app(IssuePurchaseOrder::class)($order, $actor);
        $receipt = app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $supplier->id, 'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->toDateString(),
            'lines' => [['purchase_order_line_id' => $order->lines()->sole()->id, 'item_id' => $item->id, 'location_id' => $location->id, 'received_qty' => $quantity, 'received_uom_id' => $unit->id]],
        ], $actor, $key);
        if ($composition !== null) {
            app(RecordReceiptComposition::class)($receipt->lines()->sole(), $composition, 'Counted on arrival, held for inspection.', $actor);
        }
        app(PostGoodsReceipt::class)($receipt, $actor);

        return $receipt->lines()->sole();
    }

    /** @param  list<array<string,mixed>>|null  $portion */
    private function decide(GoodsReceiptLine $line, User $actor, string $outcome, string $quantity, string $key, ?array $portion): void
    {
        app(PostQualityDisposition::class)($line, ['disposition' => $outcome, 'qty' => $quantity, 'reason' => 'Inspected: '.strtolower(str_replace('_', ' ', $outcome)).'.',
            ...($portion === null ? [] : ['package_portion' => $portion])], $actor->id, $key);
    }

    private function groupedScenario(User $actor): void
    {
        $item = $this->item($actor, 'demo-rejected-exit', 'XYZ Paints emulsion — rejected stock exit');
        $can = ItemPackaging::query()->where('item_id', $item->id)->where('code', 'can-5l')->first()
            ?? app(MaintainItemPackaging::class)(['item_id' => $item->id, 'code' => 'can-5l', 'name' => 'Sealed 5 L can', 'stock_quantity' => '5'], $actor);
        $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
        $location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
        app(PostInventoryMovement::class)(['item_id' => $item->id, 'location_id' => $location->id, 'qty' => '15', 'uom_id' => $item->stock_uom_id, 'moved_at' => now(),
            'source_type' => 'DIRECT_RECEIPT', 'source_id' => 996301, 'unit_cost' => '9', 'base_unit_cost' => '9', 'currency_id' => $currency->id, 'fx_rate' => '1.000000000']);
        $count = app(RecordPackageCheckpoint::class)(['item_id' => $item->id, 'location_id' => $location->id, 'request_key' => 'demo-xyz-exit-count',
            'reason' => 'Fictional opening count: three sealed 5 L cans.', 'expected_quantity' => '15',
            'expected_layers' => StockLayer::query()->where('item_id', $item->id)->where('location_id', $location->id)->pluck('qty_remaining', 'id')->all(),
            'composition' => [['packaging_id' => $can->id, 'count' => 3]]], $actor);
        app(ActivateGroupedPackageTracking::class)(['item_id' => $item->id, 'request_key' => 'demo-xyz-exit-activation', 'reason' => 'Track emulsion by sealed can.', 'checkpoints' => [$count->id]], $actor);

        $line = $this->delivery($actor, $item, '19', '[Rejected-stock exit demo] 3 cans + 4 L loose, decided by QA.', 'demo-xyz-exit-receipt',
            [['packaging_id' => $can->id, 'count' => 3], ['quantity' => '4']], $can);
        $one = fn (): array => [['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1]];
        $this->decide($line, $actor, 'PASS', '5', 'demo-xyz-exit-pass', $one());
        $this->decide($line, $actor, 'RETURN_TO_SUPPLIER', '5', 'demo-xyz-exit-return', $one());
        $this->decide($line, $actor, 'SCRAP', '9', 'demo-xyz-exit-scrap', [...$one(), ['kind' => 'LOOSE', 'quantity' => '4']]);

        $return = app(CreateSupplierReturn::class)(['reason' => 'Failed viscosity test on the delivery.', 'supplier_reference' => 'RMA-DEMO-1',
            'expected_credit_amount' => '50', 'expected_credit_currency_id' => $currency->id, 'expected_credit_reference' => 'CN-DEMO-1',
            'lines' => [['goods_receipt_line_id' => $line->id, 'quantity' => '5', 'package_portion' => $one()]]], $actor);
        app(PostSupplierReturn::class)($return, $actor);
        // A part of the scrap decision: one can and 2 of the 4 L loose leave; 2 L loose stay rejected awaiting scrap.
        $scrap = app(CreateStockScrap::class)(['reason' => 'Contaminated: written off.', 'disposal_method' => 'Licensed waste collector',
            'lines' => [['goods_receipt_line_id' => $line->id, 'quantity' => '7', 'package_portion' => [...$one(), ['kind' => 'LOOSE', 'quantity' => '2']]]]], $actor);
        app(PostStockScrap::class)($scrap, $actor);
    }

    private function quantityOnlyScenario(User $actor): void
    {
        $item = $this->item($actor, 'demo-rejected-exit-qty', 'XYZ Paints thinner — rejected stock exit (quantity only)');
        $line = $this->delivery($actor, $item, '10', '[Rejected-stock exit demo] A quantity-only delivery with 4 L rejected.', 'demo-xyz-exit-qty-receipt');
        $this->decide($line, $actor, 'PASS', '6', 'demo-xyz-exit-qty-pass', null);
        $this->decide($line, $actor, 'RETURN_TO_SUPPLIER', '4', 'demo-xyz-exit-qty-return', null);
        // Left as a draft: nothing leaves the shelf until someone posts it.
        app(CreateSupplierReturn::class)(['reason' => 'Wrong grade delivered.', 'supplier_reference' => 'RMA-DEMO-2',
            'lines' => [['goods_receipt_line_id' => $line->id, 'quantity' => '4']]], $actor);
    }
}
