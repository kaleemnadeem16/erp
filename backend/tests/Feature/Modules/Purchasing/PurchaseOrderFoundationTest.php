<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\Uom;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartySite;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Models\SupplierSite;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\PurchasingSetting;
use App\Modules\Purchasing\Support\OverReceiptMode;
use App\Modules\Purchasing\Support\PurchaseOrderFxPolicy;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use App\Support\UomDimension;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_defaults_and_enums_are_typed(): void
    {
        $settings = PurchasingSetting::create([])->refresh();

        $this->assertSame(1, $settings->company_id);
        $this->assertSame(OverReceiptMode::Block, $settings->over_receipt_mode);
        $this->assertSame('0.0000', $settings->over_receipt_tolerance_percent);
        $this->assertTrue($settings->purchase_order_required);
    }

    public function test_purchase_order_and_line_defaults_cast_and_relate(): void
    {
        [$order, $item, $uom] = $this->makeOrder();
        $line = PurchaseOrderLine::create($this->lineAttributes($order, $item, $uom));

        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertSame(PurchaseOrderFxPolicy::Floating, $order->fx_policy);
        $this->assertSame($order->id, $line->purchaseOrder->id);
        $this->assertSame($item->id, $line->item->id);
        $this->assertSame(1, $line->line_number);
    }

    public function test_supplier_site_must_belong_to_selected_supplier(): void
    {
        [$order] = $this->makeOrder();
        [$otherSupplier, $otherSite] = $this->makeSupplier('OTHER');

        $this->expectException(DomainInvariantException::class);
        $order->update([
            'supplier_account_id' => $order->supplier_account_id,
            'supplier_site_id' => $otherSite->id,
        ]);
    }

    public function test_postgres_smallint_rejects_line_number_above_32767(): void
    {
        [$order, $item, $uom] = $this->makeOrder();

        $this->expectException(QueryException::class);
        PurchaseOrderLine::create($this->lineAttributes($order, $item, $uom, 32768));
    }

    /** @return array{PurchaseOrder, Item, Uom} */
    private function makeOrder(): array
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        [$supplier, $site] = $this->makeSupplier('SUP');
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $item = Item::create(['code' => 'item', 'name' => 'Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id]);
        $order = PurchaseOrder::create([
            'supplier_account_id' => $supplier->id, 'supplier_site_id' => $site->id,
            'currency_id' => $currency->id, 'fx_rate' => '1.000000000',
            'fx_rate_date' => '2026-09-02', 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => '2026-09-02',
        ])->refresh();

        return [$order, $item, $uom];
    }

    /** @return array{SupplierAccount, SupplierSite} */
    private function makeSupplier(string $code): array
    {
        $party = Party::create(['code' => $code, 'name' => $code]);
        $account = ChartOfAccount::create(['code' => 'pay-'.$code, 'name' => 'Payable '.$code, 'type' => AccountType::Liability]);
        $supplier = SupplierAccount::create(['party_id' => $party->id, 'code' => $code, 'payment_terms_days' => 30, 'payable_account_id' => $account->id]);
        $partySite = PartySite::create(['party_id' => $party->id, 'code' => 'main', 'name' => 'Main']);
        $site = SupplierSite::create(['supplier_account_id' => $supplier->id, 'party_site_id' => $partySite->id, 'role' => 'ORDER_FROM']);

        return [$supplier, $site];
    }

    /** @return array<string, mixed> */
    private function lineAttributes(PurchaseOrder $order, Item $item, Uom $uom, int $lineNumber = 1): array
    {
        return [
            'purchase_order_id' => $order->id, 'line_number' => $lineNumber, 'item_id' => $item->id,
            'description' => 'Item', 'ordered_qty' => '2.0000', 'purchase_uom_id' => $uom->id,
            'ordered_stock_qty' => '2.0000', 'stock_uom_id' => $uom->id, 'uom_factor' => '1.000000000',
            'unit_price' => '10.0000', 'line_amount' => '20.0000',
            'base_unit_price' => '10.0000', 'base_line_amount' => '20.0000',
        ];
    }
}
