<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Purchasing\Actions\BuildPurchaseOrderLineAttributes;
use App\Modules\Purchasing\Actions\MaintainSupplierOffer;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierOffer;
use Database\Seeders\Demo\PackagedPurchasingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PackagedPurchasingDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
    }

    public function test_named_nested_and_equal_content_packages_have_distinct_identity(): void
    {
        $packages = ItemPackaging::query()->where('item_id', $this->item()->id)->get()->keyBy('code');
        $this->assertCount(7, $packages);
        $this->assertSame('2.000000000', $packages['box-20-small']->stock_quantity);
        $this->assertSame('2.000000000', $packages['box-4-large']->stock_quantity);
        $this->assertNotSame($packages['box-20-small']->id, $packages['box-4-large']->id);
        $this->assertSame($packages['bottle-100ml']->id, $packages['box-20-small']->inner_packaging_id);
        $this->assertSame(20, $packages['box-20-small']->inner_count);
        $this->assertSame($packages['can-5l']->id, $packages['drum-200l']->inner_packaging_id);
        $this->assertSame(40, $packages['drum-200l']->inner_count);
        $this->assertSame('200.000000000', $packages['drum-200l']->stock_quantity);
    }

    public function test_every_order_scenario_has_the_claimed_quantity_and_price_basis(): void
    {
        foreach ([
            ['live-draft', '20.0000', '17000.0000', '1.0000'],
            ['equal-content', '20.0000', '17000.0000', '1.0000'],
            ['per-100-litres', '20.0000', '17000.0000', '100.0000'],
            ['loose-6-5-litres', '6.5000', '5525.0000', '1.0000'],
            ['can-and-drum', '200.0000', '170000.0000', '1.0000'],
            ['minimum-exception', '6.0000', '5100.0000', '1.0000'],
            ['multiple-exception', '14.0000', '11900.0000', '1.0000'],
            ['old-price-issued', '10.0000', '8500.0000', '1.0000'],
            ['future-price', '10.0000', '9000.0000', '1.0000'],
            ['remainder-replacement', '8.0000', '6800.0000', '1.0000'],
        ] as [$key, $stock, $amount, $denominator]) {
            $line = $this->order($key)->lines->sole();
            $this->assertSame($stock, $line->ordered_stock_qty, $key);
            $this->assertSame($amount, $line->line_amount, $key);
            $this->assertSame($denominator, $line->price_quantity, $key);
        }
        $this->assertNull($this->order('equal-content')->lines->sole()->price_uom_id);
        $this->assertNull($this->order('loose-6-5-litres')->lines->sole()->item_packaging_id);
        $this->assertSame('850.0000', $this->order('old-price-issued')->lines->sole()->unit_price);
        $this->assertSame('900.0000', $this->offer('DEMO-PRICE-CHANGE')->unit_price);
        $this->assertSame('850.0000', $this->order('old-price-issued')->lines->sole()->offer_snapshot['unit_price']);
        $this->assertFalse(ItemPackaging::query()->findOrFail($this->order('archived-package-issued')->lines->sole()->item_packaging_id)->active);
        $this->assertNotEmpty($this->order('minimum-exception')->lines->sole()->ordering_exception_reason);
        $this->assertTrue($this->order('multiple-exception')->lines->sole()->offer_snapshot['constraint_warning']);
    }

    public function test_partial_loose_and_completed_receipts_have_real_stock_and_cost_evidence(): void
    {
        foreach ([['partial-six', '12.0000', '10200.0000', 'POSTED'], ['partial-four-draft', '8.0000', '6800.0000', 'DRAFT'],
            ['complete-six', '12.0000', '10200.0000', 'POSTED'], ['complete-four', '8.0000', '6800.0000', 'POSTED'],
            ['loose-one-half', '1.5000', '1275.0000', 'POSTED']] as [$key, $stock, $amount, $status]) {
            $receipt = GoodsReceipt::query()->where('idempotency_key', 'demo-pack-'.$key)->firstOrFail();
            $this->assertSame($status, $receipt->status->value);
            $this->assertSame($stock, $receipt->lines->sole()->stock_qty);
            $this->assertSame($amount, $receipt->lines->sole()->provisional_amount);
        }
        $this->assertSame('ISSUED', $this->order('partial-6-plus-4')->status->value);
        $this->assertSame('CLOSED', $this->order('complete-6-plus-4')->status->value);
        $pool = AvcoPool::query()->where('item_id', $this->item()->id)->sole();
        $this->assertSame('45.5000', $pool->qty_on_hand);
        $this->assertSame('38675.0000', $pool->inventory_value);
        $this->assertNull(GoodsReceipt::query()->where('idempotency_key', 'demo-pack-loose-one-half')->sole()->lines->sole()->received_package_qty);
    }

    public function test_supplier_unavailability_and_changed_remainder_use_linked_replacements(): void
    {
        $cancelled = $this->order('unavailable-cancelled');
        $this->assertSame('CANCELLED', $cancelled->status->value);
        $this->assertSame($cancelled->id, $this->order('unavailable-replacement')->replaces_purchase_order_id);
        $short = $this->order('partial-short-closed');
        $this->assertSame('CLOSED', $short->status->value);
        $this->assertSame('8.0000', $short->lines->sole()->short_closed_stock_qty);
        $this->assertSame($short->id, $this->order('remainder-replacement')->replaces_purchase_order_id);
        $this->assertNotSame($short->lines->sole()->item_packaging_id, $this->order('remainder-replacement')->lines->sole()->item_packaging_id);
    }

    public function test_the_live_draft_really_rejects_the_documented_invalid_choices(): void
    {
        $order = $this->order('live-draft');
        $line = $order->lines->sole()->getAttributes();
        foreach (['DEMO-EXPIRED', 'DEMO-FUTURE', 'DEMO-INACTIVE', 'DEMO-ALT-SUPPLIER'] as $sku) {
            $offer = $this->offer($sku);
            $this->assertRejected($order, array_replace($line, ['supplier_offer_id' => $offer->id, 'item_packaging_id' => $offer->item_packaging_id]), 'supplier_offer_id');
        }
        $this->assertRejected($order, array_replace($line, ['ordered_qty' => '0.5']), 'ordered_qty');
        $this->assertRejected($order, array_replace($line, ['ordered_qty' => '7']), 'ordering_exception_reason');
        $this->assertRejected($order, array_replace($line, ['supplier_offer_id' => null, 'price_uom_id' => Uom::query()->where('code', 'kg')->sole()->id]), 'price_uom_id');
        $this->assertTrue($this->offer('DEMO-BOX-L')->preferred);
    }

    public function test_rerun_preserves_manual_progress_master_edits_and_unrelated_records(): void
    {
        $actor = User::query()->where('email', config('demo.user.email'))->sole();
        $draft = GoodsReceipt::query()->where('idempotency_key', 'demo-pack-partial-four-draft')->sole();
        app(PostGoodsReceipt::class)($draft, $actor);
        $offer = $this->offer('DEMO-PRICE-CHANGE');
        app(MaintainSupplierOffer::class)(['unit_price' => '930'], $actor, $offer);
        $local = app(MaintainItemPackaging::class)(['item_id' => $this->item()->id, 'code' => 'local-can', 'name' => 'Unrelated local can', 'stock_quantity' => '7'], $actor);
        $before = PurchaseOrder::query()->where('notes', 'like', '[Pack demo:%')->with('lines')->get()->toArray();
        $receipts = GoodsReceipt::query()->where('idempotency_key', 'like', 'demo-pack-%')->with('lines')->get()->toArray();
        $pool = AvcoPool::query()->where('item_id', $this->item()->id)->sole()->getAttributes();
        $this->artisan('demo:seed')->assertSuccessful();
        $this->assertSame($before, PurchaseOrder::query()->where('notes', 'like', '[Pack demo:%')->with('lines')->get()->toArray());
        $this->assertSame($receipts, GoodsReceipt::query()->where('idempotency_key', 'like', 'demo-pack-%')->with('lines')->get()->toArray());
        $this->assertSame($pool, AvcoPool::query()->where('item_id', $this->item()->id)->sole()->getAttributes());
        $this->assertSame('930.0000', $offer->refresh()->unit_price);
        $this->assertNotNull($local->fresh());
    }

    public function test_module_seeder_refuses_production(): void
    {
        app()->instance('env', 'production');
        $this->expectException(\RuntimeException::class);
        (new PackagedPurchasingDemoSeeder)->run();
    }

    public function test_item_context_demo_has_an_archived_relationship_and_preserves_restoration(): void
    {
        $relationships = ItemSupplier::query()->where('item_id', $this->item()->id)->get();
        $this->assertCount(3, $relationships);
        $archived = $relationships->where('active', false)->sole();
        $this->assertStringContainsString('restore', $archived->supplier_item_code);
        $actor = User::query()->where('email', config('demo.user.email'))->sole();
        $this->actingAs($actor)->patchJson('/api/v1/item-suppliers/'.$archived->id, ['active' => true])->assertOk();
        $this->artisan('demo:seed')->assertSuccessful();
        $this->assertTrue($archived->refresh()->active);
        $this->assertSame(3, ItemSupplier::query()->where('item_id', $this->item()->id)->count());
    }

    private function assertRejected(PurchaseOrder $order, array $line, string $field): void
    {
        try {
            app(BuildPurchaseOrderLineAttributes::class)($order, $line);
            $this->fail('Expected rejection for '.$field);
        } catch (DomainInvariantException $exception) {
            $this->assertSame($field, $exception->field);
        }
    }

    private function item(): Item
    {
        return Item::query()->where('code', 'demo-pack-thinner')->sole();
    }

    private function order(string $key): PurchaseOrder
    {
        return PurchaseOrder::query()->where('notes', 'like', '[Pack demo:'.$key.']%')->with('lines')->sole();
    }

    private function offer(string $sku): SupplierOffer
    {
        return SupplierOffer::query()->where('supplier_item_code', $sku)->sole();
    }
}
