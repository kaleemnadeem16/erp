<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordReceiptComposition;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\PermissionRegistry;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ReceiptPackageEvidenceDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(1);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $base = Item::query()->where('code', 'demo-pack-thinner')->firstOrFail();
        $unit = Uom::query()->where('code', 'l')->firstOrFail();
        $item = Item::withTrashed()->where('code', 'demo-physical-receipt-thinner')->first()
            ?? app(CreateItem::class)([
                'code' => 'demo-physical-receipt-thinner', 'name' => 'Thinner — observed receipt composition demo',
                'description' => 'Commercial boxes remain frozen; actual sealed/loose arrival evidence is recorded separately. Tracking remains quantity-only.',
                'type' => 'RAW', 'stock_uom_id' => $unit->id, 'category_id' => $base->category_id,
                'valuation_method' => 'AVCO', 'avco_pool_scope' => 'COMPANY_ITEM', 'tracking' => 'NONE',
                'inventory_account_id' => $base->inventory_account_id,
                'is_stocked' => true, 'is_purchasable' => true, 'is_sellable' => false,
            ], $actor);
        $packages = [];
        foreach (['box-2l' => 'Agreed 2 L box', 'can-2l' => 'Observed 2 L can'] as $code => $name) {
            $packages[$code] = ItemPackaging::query()->where('item_id', $item->id)->where('code', $code)->first()
                ?? app(MaintainItemPackaging::class)(['item_id' => $item->id, 'code' => $code, 'name' => $name, 'stock_quantity' => '2'], $actor);
        }
        $supplier = SupplierAccount::query()->where('code', 'sup-pakchem')->firstOrFail();
        $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
        $location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
        foreach ([
            ['sealed-and-loose', false, [['packaging_id' => $packages['box-2l']->id, 'count' => 1], ['quantity' => '2']], 'One sealed box plus 2 L delivered open; two boxes remain the commercial basis.'],
            ['alternative-form', false, [['packaging_id' => $packages['can-2l']->id, 'count' => 2]], 'Delivered in two sealed cans; records physical form without rewriting the agreed box pricing.'],
            ['qa-held-sealed', true, [['packaging_id' => $packages['box-2l']->id, 'count' => 2]], 'Two sealed boxes physically present, initially held for QA; no automatic release/open.'],
        ] as [$key, $qa, $composition, $explanation]) {
            $marker = '[Physical receipt demo:'.$key.']';
            if (GoodsReceipt::query()->where('idempotency_key', 'demo-physical-receipt-'.$key)->exists()
                || PurchaseOrder::query()->where('notes', 'like', $marker.'%')->exists()) {
                continue;
            }
            DB::transaction(function () use ($actor, $item, $unit, $packages, $supplier, $currency, $location, $marker, $key, $qa, $composition, $explanation): void {
                $order = app(CreatePurchaseOrder::class)([
                    'supplier_account_id' => $supplier->id, 'currency_id' => $currency->id,
                    'fx_rate' => '1.000000000', 'fx_rate_date' => now()->toDateString(),
                    'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => now()->toDateString(),
                    'notes' => $marker.' '.$explanation,
                    'lines' => [['line_number' => 1, 'item_id' => $item->id, 'description' => $item->name,
                        'ordered_qty' => '2', 'purchase_uom_id' => $unit->id, 'item_packaging_id' => $packages['box-2l']->id,
                        'unit_price' => '850', 'price_uom_id' => $unit->id, 'price_quantity' => '1', 'qa_required' => $qa]],
                ], $actor);
                app(IssuePurchaseOrder::class)($order, $actor);
                $line = $order->lines()->sole();
                $receipt = app(CreateGoodsReceipt::class)([
                    'supplier_account_id' => $supplier->id, 'physical_arrival_at' => now()->toIso8601String(),
                    'posting_date' => now()->toDateString(),
                    'lines' => [['purchase_order_line_id' => $line->id, 'item_id' => $item->id, 'location_id' => $location->id,
                        'received_qty' => '2', 'received_uom_id' => $unit->id, 'item_packaging_id' => $packages['box-2l']->id]],
                ], $actor, 'demo-physical-receipt-'.$key);
                app(RecordReceiptComposition::class)($receipt->lines()->sole(), $composition, $explanation, $actor);
                app(PostGoodsReceipt::class)($receipt, $actor);
            });
        }
    }
}
