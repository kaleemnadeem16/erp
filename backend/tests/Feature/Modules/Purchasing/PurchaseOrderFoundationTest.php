<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Uom;
use App\Models\User;
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
use App\Support\RateType;
use App\Support\UomDimension;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

class PurchaseOrderFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATIONAL_PERMISSIONS = [
        'inventory.item.manage',
        'purchasing.purchase-order.create',
        'purchasing.purchase-order.issue',
        'purchasing.purchase-order.cancel',
        'purchasing.purchase-order.short-close',
        'purchasing.goods-receipt.create',
        'purchasing.goods-receipt.post',
        'purchasing.goods-receipt.reverse',
        'purchasing.quality-disposition.post',
        'purchasing.item-supplier.manage',
    ];

    /** Existing Purchasing feature tests exercise successful operational paths by default. */
    public function actingAs(UserContract $user, $guard = null): static
    {
        PurchasingActor::grant($user, self::OPERATIONAL_PERMISSIONS);

        return parent::actingAs($user, $guard);
    }

    protected function actingAsUnprivileged(UserContract $user, $guard = null): static
    {
        return parent::actingAs($user, $guard);
    }

    protected function purchasingActor(): User
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            $actor = User::factory()->create();
            $this->actingAs($actor);
        }

        return $actor;
    }

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

    /**
     * Gives the company a base currency (PKR) and one governed USD to PKR rate on the fixtures' rate date, which is what a purchase order's typed rate is
     * reconciled with (issue #90). Call it BEFORE makeOrder(): the base currency cannot change once a purchase order exists. A second call with another
     * rate replaces that rate.
     */
    protected function governFx(string $rate = '1.000000000'): void
    {
        $pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Pakistani Rupee', 'symbol' => 'Rs']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $pkr->id]);
        $usd = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        ExchangeRate::query()->updateOrCreate(
            ['from_currency_id' => $usd->id, 'to_currency_id' => $pkr->id, 'rate_date' => '2026-09-02', 'rate_type' => RateType::Spot->value],
            ['rate' => $rate, 'source' => 'manual'],
        );
    }

    /** @return array{PurchaseOrder, Item, Uom} */
    protected function makeOrder(): array
    {
        // Default fixture: the order currency (USD) is the company's base currency, so rate 1 is the governed rate. Done before the first order exists;
        // a test that wants a foreign currency calls governFx() first, and a test that sets the base itself sets it to this same currency.
        $usd = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        if (Company::query()->findOrFail(1)->base_currency_id === null) {
            Company::query()->findOrFail(1)->update(['base_currency_id' => $usd->id]);
        }
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
    protected function makeSupplier(string $code): array
    {
        $party = Party::create(['code' => $code, 'name' => $code]);
        $account = ChartOfAccount::create(['code' => 'pay-'.$code, 'name' => 'Payable '.$code, 'type' => AccountType::Liability]);
        $supplier = SupplierAccount::create(['party_id' => $party->id, 'code' => $code, 'payment_terms_days' => 30, 'payable_account_id' => $account->id]);
        $partySite = PartySite::create(['party_id' => $party->id, 'code' => 'main', 'name' => 'Main']);
        $site = SupplierSite::create(['supplier_account_id' => $supplier->id, 'party_site_id' => $partySite->id, 'role' => 'ORDER_FROM']);

        return [$supplier, $site];
    }

    /** @return array<string, mixed> */
    protected function lineAttributes(PurchaseOrder $order, Item $item, Uom $uom, int $lineNumber = 1): array
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
