<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Actions\CancelPurchaseOrder;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\MaintainSupplierOffer;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\ShortClosePurchaseOrderLine;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierOffer;
use App\Support\PermissionRegistry;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0080's purchasing story, on a dedicated thinner item so other stock demos stay unchanged.
 * Packages/offers and historical documents are created once; reruns preserve manual edits and
 * workflow progress. Notes/labels explain each scenario, not merely its demo ownership code.
 * Negative cases are tried against the live draft, never persisted as invalid documents.
 */
final class PackagedPurchasingDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    private User $actor;

    private Item $item;

    private Uom $litre;

    private Currency $currency;

    private SupplierAccount $supplier;

    private Location $location;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        $registry = app(PermissionRegistry::class);
        $registry->registerForTeam(1);
        $this->actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $this->actor->syncPermissions($registry->all());
        $this->litre = Uom::query()->where('code', 'l')->firstOrFail();
        $this->currency = Currency::query()->where('code', 'PKR')->firstOrFail();
        $this->supplier = SupplierAccount::query()->where('code', 'sup-pakchem')->firstOrFail();
        $this->location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
        $this->item = Item::query()->firstOrCreate(['code' => 'demo-pack-thinner'], [
            'name' => 'Thinner — named packages and price-basis demo',
            'description' => 'Two different 2 L boxes, nested bottles, cans and drums. Compare package/loose orders, partial deliveries, supplier changes and replacement agreements. Stock is quantity-only in litres.',
            'type' => 'RAW', 'stock_uom_id' => $this->litre->id, 'quantity_precision' => '0.001',
            'category_id' => Category::query()->where('code', 'demo-solvents')->firstOrFail()->id,
            'is_stocked' => true, 'is_purchasable' => true, 'is_sellable' => false,
            'valuation_method' => 'AVCO', 'avco_pool_scope' => 'COMPANY_ITEM', 'tracking' => 'NONE', 'status' => 'ACTIVE',
        ]);

        $small = $this->package('bottle-100ml', 'Bottle 100 mL — inner pack', '0.1');
        $large = $this->package('bottle-500ml', 'Bottle 500 mL — inner pack', '0.5');
        $box = $this->package('box-20-small', 'Box 20 × 100 mL — 2 L total', '2', $small, 20);
        $otherBox = $this->package('box-4-large', 'Box 4 × 500 mL — also 2 L, different package', '2', $large, 4);
        $can = $this->package('can-5l', 'Can 5 L — larger fixed package', '5');
        $drum = $this->package('drum-200l', 'Drum 200 L — 40 inner cans', '200', $can, 40);
        $retired = $this->package('old-box-2l', 'Old 2 L box — archived after issue; agreement preserved', '2');

        $rel = $this->relationship($this->supplier);
        $preferred = $this->offer($rel, 'DEMO-BOX-L', $box, '850', $this->litre->id, '1', ['preferred' => true, 'minimum_order_qty' => '5', 'order_multiple' => '5']);
        $perBox = $this->offer($rel, 'DEMO-BOX-EACH', $otherBox, '1700', null);
        $perHundred = $this->offer($rel, 'DEMO-PER-100L', $box, '85000', $this->litre->id, '100');
        $loose = $this->offer($rel, 'DEMO-LOOSE', null, '850', $this->litre->id);
        $history = $this->offer($rel, 'DEMO-PRICE-CHANGE', $box, '850', $this->litre->id);
        $alternative = $this->relationship(SupplierAccount::query()->where('code', 'sup-coatings')->firstOrFail());
        $this->offer($alternative, 'DEMO-ALT-SUPPLIER', $otherBox, '1800', null, '1', ['lead_time_days' => 2]);
        $this->offer($rel, 'DEMO-EXPIRED', $can, '4000', null, '1', ['valid_to' => '2000-01-01']);
        $this->offer($rel, 'DEMO-FUTURE', $can, '4200', null, '1', ['valid_from' => '2099-01-01']);
        $this->offer($rel, 'DEMO-INACTIVE', $can, '4100', null, '1', ['active' => false]);

        $this->order('live-draft', 'Try package/loose choices, preferred offer and preview. 10 boxes × 2 L at 850/L = 20 L / 17,000. Try 0.5 box (blocked) or 7 boxes (reason needed).', $this->line($preferred, '10'));
        $this->order('equal-content', 'Different box identity: 4 × 500 mL instead of 20 × 100 mL. 10 boxes priced at 1,700 per box = 20 L / 17,000.', $this->line($perBox, '10'));
        $this->order('per-100-litres', 'Separate price denominator: 10 boxes = 20 L at 85,000 per 100 L = 17,000.', $this->line($perHundred, '10'));
        $this->order('loose-6-5-litres', 'Loose 6.5 L at 850/L = 5,525; no package count.', $this->line($loose, '6.5'));
        $this->order('can-and-drum', 'Large package: one drum contains 40 × 5 L cans = 200 L; 170,000. Left draft to inspect without approval.', $this->manualLine($drum, '1'));
        $this->order('minimum-exception', 'Only 3 boxes: below minimum 5 and outside multiple 5. Quantity stays 3; supplier agreement is recorded.', $this->line($preferred, '3') + ['ordering_exception_reason' => 'Supplier agreed to a three-box trial below its minimum.']);
        $this->order('multiple-exception', '7 boxes exceeds minimum 5 but is outside multiple 5. Quantity stays 7; reason is recorded.', $this->line($preferred, '7') + ['ordering_exception_reason' => 'Supplier agreed to seven boxes for this order.']);

        $this->order('complete-6-plus-4', 'Completed example: 6 + 4 boxes received as 12 + 8 L, total 20 L / 17,000.', $this->line($preferred, '10'), function (PurchaseOrder $order): void {
            $order = app(IssuePurchaseOrder::class)($order, $this->actor);
            $this->receipt($order, 'complete-six', '6', true, true);
            $this->receipt($order, 'complete-four', '4', true, true);
        });
        $this->order('loose-receipt', 'Two boxes agreed = 4 L. Receive loose 1.5 L against the issued package price (1,275); 2.5 L remains.', $this->manualLine($box, '2'), function (PurchaseOrder $order): void {
            $order = app(IssuePurchaseOrder::class)($order, $this->actor);
            $this->receipt($order, 'loose-one-half', '1.5', false, true);
        });
        $this->order('old-price-issued', 'Issued at 850/L (8,500). The offer now says 900/L; historical agreement remains 850/L. Compare future-price.', $this->line($history, '5'), function (PurchaseOrder $order) use ($history): void {
            app(IssuePurchaseOrder::class)($order, $this->actor);
            app(MaintainSupplierOffer::class)(['unit_price' => '900'], $this->actor, $history);
        });
        $this->order('future-price', 'New draft uses updated offer at 900/L: 5 boxes = 10 L / 9,000. Compare old-price-issued.', $this->line($history->refresh(), '5'));
        $this->order('archived-package-issued', 'Issued 2 L package later archived. Existing order still receives using its frozen package; future orders use another definition.', $this->manualLine($retired, '2'), function (PurchaseOrder $order) use ($retired): void {
            app(IssuePurchaseOrder::class)($order, $this->actor);
            app(MaintainItemPackaging::class)(['active' => false], $this->actor, $retired);
        });
        $cancelled = $this->order('unavailable-cancelled', 'Supplier cannot supply this box; cancel before any receipt. Compare unavailable-replacement.', $this->manualLine($box, '5'), function (PurchaseOrder $order): void {
            $order = app(IssuePurchaseOrder::class)($order, $this->actor);
            app(CancelPurchaseOrder::class)($order, 'Supplier cannot supply the agreed box; replacing with the other 2 L box.', $this->actor);
        });
        $this->order('unavailable-replacement', 'Replacement for cancelled unavailable-cancelled: same 10 L, different named box and agreed price.', $this->line($perBox, '5'), null, $cancelled);
        $short = $this->order('partial-short-closed', '10 boxes agreed, 6 received (12 L); remaining 4 boxes (8 L) short-closed after supplier changed the package. Compare remainder-replacement.', $this->line($preferred, '10'), function (PurchaseOrder $order): void {
            $order = app(IssuePurchaseOrder::class)($order, $this->actor);
            $this->receipt($order, 'short-close-six', '6', true, true);
            app(ShortClosePurchaseOrderLine::class)($order->lines->sole(), $this->actor);
        });
        $this->order('remainder-replacement', 'Replacement for only the short-closed 8 L: 4 of the alternative 2 L box. Original 12 L receipt and old agreement remain.', $this->line($perBox, '4'), null, $short);

        // Last stock scenario: the live draft must not precede later seeded valuation history.
        $this->order('partial-6-plus-4', '10 boxes agreed; 6 posted = 12 L / 10,200. Four boxes remain in a draft receipt: post it to complete 20 L / 17,000.', $this->line($preferred, '10'), function (PurchaseOrder $order): void {
            $order = app(IssuePurchaseOrder::class)($order, $this->actor);
            $this->receipt($order, 'partial-six', '6', true, true);
            $this->receipt($order, 'partial-four-draft', '4', true, false);
        });
    }

    private function package(string $code, string $name, string $qty, ?ItemPackaging $inner = null, ?int $count = null): ItemPackaging
    {
        return ItemPackaging::query()->where('item_id', $this->item->id)->where('code', $code)->first()
            ?? app(MaintainItemPackaging::class)(['item_id' => $this->item->id, 'code' => $code, 'name' => $name, 'stock_quantity' => $qty,
                'quantity_precision' => '1', 'inner_packaging_id' => $inner?->id, 'inner_count' => $count], $this->actor);
    }

    private function relationship(SupplierAccount $supplier): ItemSupplier
    {
        return ItemSupplier::query()->firstOrCreate(['item_id' => $this->item->id, 'supplier_account_id' => $supplier->id],
            ['supplier_item_code' => 'DEMO-PACK-THINNER', 'purchase_uom_id' => $this->litre->id, 'active' => true]);
    }

    private function offer(ItemSupplier $rel, string $sku, ?ItemPackaging $package, string $price, ?int $priceUnit, string $denominator = '1', array $extra = []): SupplierOffer
    {
        return SupplierOffer::query()->where('item_supplier_id', $rel->id)->where('supplier_item_code', $sku)->first()
            ?? app(MaintainSupplierOffer::class)($extra + ['item_supplier_id' => $rel->id, 'supplier_item_code' => $sku,
                'item_packaging_id' => $package?->id, 'purchase_uom_id' => $this->litre->id, 'unit_price' => $price,
                'currency_id' => $this->currency->id, 'price_uom_id' => $priceUnit, 'price_quantity' => $denominator], $this->actor);
    }

    private function line(SupplierOffer $offer, string $qty): array
    {
        return ['line_number' => 1, 'item_id' => $this->item->id, 'description' => $this->item->name,
            'ordered_qty' => $qty, 'purchase_uom_id' => $this->litre->id, 'supplier_offer_id' => $offer->id,
            'item_packaging_id' => $offer->item_packaging_id, 'unit_price' => $offer->unit_price,
            'price_uom_id' => $offer->price_uom_id, 'price_quantity' => $offer->price_quantity, 'qa_required' => false];
    }

    private function manualLine(ItemPackaging $package, string $qty): array
    {
        return ['line_number' => 1, 'item_id' => $this->item->id, 'description' => $this->item->name,
            'ordered_qty' => $qty, 'purchase_uom_id' => $this->litre->id, 'item_packaging_id' => $package->id,
            'unit_price' => '850', 'price_uom_id' => $this->litre->id, 'price_quantity' => '1', 'qa_required' => false];
    }

    private function order(string $key, string $explanation, array $line, ?callable $transition = null, ?PurchaseOrder $replaces = null): PurchaseOrder
    {
        $marker = '[Pack demo:'.$key.']';
        $existing = PurchaseOrder::query()->where('notes', 'like', $marker.'%')->first();
        if ($existing) {
            return $existing; // Never reissue, repost, reset a user's workflow or recompute historical evidence.
        }

        return DB::transaction(function () use ($marker, $explanation, $line, $transition, $replaces): PurchaseOrder {
            $order = app(CreatePurchaseOrder::class)(['supplier_account_id' => $this->supplier->id,
                'currency_id' => $this->currency->id, 'fx_rate' => '1.000000000', 'fx_rate_date' => now()->toDateString(),
                'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => now()->toDateString(),
                'notes' => $marker.' '.$explanation, 'replaces_purchase_order_id' => $replaces?->id, 'lines' => [$line]]);
            if ($transition) {
                $transition($order);
            }

            return $order->refresh()->load('lines');
        });
    }

    private function receipt(PurchaseOrder $order, string $key, string $qty, bool $packed, bool $post): void
    {
        $marker = 'demo-pack-'.$key;
        if (GoodsReceipt::query()->where('idempotency_key', $marker)->exists()) {
            return;
        }
        $line = $order->lines->sole();
        $receipt = app(CreateGoodsReceipt::class)(['supplier_account_id' => $order->supplier_account_id,
            'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->toDateString(),
            'notes' => 'Pack demo: '.$key.' — '.$qty.($packed ? ' agreed packages' : ' loose litres').'; priced from the issued agreement.',
            'lines' => [['purchase_order_line_id' => $line->id, 'item_id' => $this->item->id, 'location_id' => $this->location->id,
                'received_qty' => $qty, 'received_uom_id' => $this->litre->id, 'item_packaging_id' => $packed ? $line->item_packaging_id : null]]], $this->actor, $marker);
        if ($post) {
            app(PostGoodsReceipt::class)($receipt, $this->actor);
        }
    }
}
