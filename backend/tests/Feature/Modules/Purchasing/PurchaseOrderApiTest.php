<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
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
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchasingPolicyChange;
use App\Modules\Purchasing\Models\PurchasingSetting;
use App\Modules\Purchasing\Support\OverReceiptMode;
use App\Support\UomDimension;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;

class PurchaseOrderApiTest extends PurchaseOrderFoundationTest
{
    public function test_buyer_can_read_purchase_orders_and_sourcing_but_not_receipts(): void
    {
        $buyer = PurchasingActor::make([
            'purchasing.purchase-order.create',
            'purchasing.item-supplier.manage',
        ]);

        $this->actingAsUnprivileged($buyer)
            ->getJson('/api/v1/purchase-orders')->assertOk();
        $this->getJson('/api/v1/item-suppliers')->assertOk();
        $this->getJson('/api/v1/goods-receipts')->assertForbidden();
    }

    public function test_document_view_does_not_reveal_commercial_values_or_monetary_sort(): void
    {
        [$order, $item, $uom] = $this->makeOrder();
        $order->lines()->create($this->lineAttributes($order, $item, $uom));
        $viewer = PurchasingActor::make(['purchasing.purchase-order.view']);

        $hidden = $this->actingAsUnprivileged($viewer)
            ->getJson("/api/v1/purchase-orders/{$order->id}?include=lines")
            ->assertOk();
        foreach (['fx_policy', 'fx_rate', 'total_amount', 'base_total_amount'] as $field) {
            $hidden->assertJsonMissingPath("data.{$field}");
        }
        foreach (['unit_price', 'discount_amount', 'tax_amount', 'line_amount', 'base_unit_price', 'base_line_amount'] as $field) {
            $hidden->assertJsonMissingPath("data.lines.0.{$field}");
        }
        $this->getJson('/api/v1/purchase-orders?include=lines&sort=total_amount')->assertForbidden();
        $this->getJson('/api/v1/purchase-orders?include=lines')
            ->assertOk()->assertJsonMissingPath('data.0.total_amount')
            ->assertJsonMissingPath('data.0.lines.0.unit_price');

        PurchasingActor::grant($viewer, ['purchasing.commercial-cost.view']);
        $this->getJson("/api/v1/purchase-orders/{$order->id}?include=lines")
            ->assertOk()->assertJsonPath('data.lines.0.unit_price', '10.0000');
        $this->getJson('/api/v1/purchase-orders?include=lines&sort=total_amount')->assertOk();
    }

    public function test_receiving_actor_can_read_purchase_orders_without_po_mutation(): void
    {
        $receiver = PurchasingActor::make(['purchasing.goods-receipt.create']);

        $this->actingAsUnprivileged($receiver)
            ->getJson('/api/v1/purchase-orders')->assertOk();
        $this->getJson('/api/v1/goods-receipts')->assertOk();
        $this->postJson('/api/v1/purchase-orders', [])->assertForbidden();
    }

    public function test_purchase_order_reads_and_writes_require_capabilities(): void
    {
        [$order, $item, $uom] = $this->makeOrder();
        $line = $order->lines()->create($this->lineAttributes($order, $item, $uom));
        $user = User::factory()->create();

        $this->actingAsUnprivileged($user)->getJson('/api/v1/purchase-orders')->assertForbidden();
        $this->getJson("/api/v1/purchase-orders/{$order->id}")->assertForbidden();
        $this->postJson('/api/v1/purchase-orders', [])->assertForbidden();
        $this->patchJson("/api/v1/purchase-orders/{$order->id}", [])->assertForbidden();
        $this->postJson("/api/v1/purchase-orders/{$order->id}/issue")->assertForbidden();
        $this->postJson("/api/v1/purchase-orders/{$order->id}/cancel", ['reason' => 'Denied'])->assertForbidden();
        $this->postJson("/api/v1/purchase-order-lines/{$line->id}/short-close")->assertForbidden();

        PurchasingActor::grant($user, ['purchasing.purchase-order.view']);
        $this->getJson('/api/v1/purchase-orders')->assertOk();
        $this->getJson("/api/v1/purchase-orders/{$order->id}")->assertOk();
        $this->postJson('/api/v1/purchase-orders', [])->assertForbidden();
    }

    public function test_purchasing_settings_are_configurable_and_purchase_order_queries_are_validated(): void
    {
        $user = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('purchasing.over-receipt-policy.manage');
        $user->givePermissionTo('purchasing.over-receipt-policy.manage');
        $this->actingAs($user)->getJson('/api/v1/purchasing-settings')
            ->assertOk()->assertJsonPath('data.over_receipt_mode', 'BLOCK');
        $this->patchJson('/api/v1/purchasing-settings', ['over_receipt_mode' => 'ALLOW_WITHIN_TOLERANCE', 'over_receipt_tolerance_percent' => '7.5000', 'reason' => 'Supplier over-delivers within an agreed band'])
            ->assertOk()->assertJsonPath('data.over_receipt_tolerance_percent', '7.5000');
        $this->getJson('/api/v1/purchase-orders?sort=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/purchase-orders?include=unknown')->assertUnprocessable();
    }

    public function test_over_receipt_policy_changes_need_permission_a_reason_and_leave_an_audit_row(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('purchasing.over-receipt-policy.manage');
        $unprivileged = User::factory()->create();
        $this->actingAs($unprivileged)
            ->patchJson('/api/v1/purchasing-settings', ['over_receipt_mode' => 'ALLOW_WITHIN_TOLERANCE', 'reason' => 'trying it on'])
            ->assertForbidden();
        $this->assertSame(0, PurchasingPolicyChange::query()->count());

        $manager = User::factory()->create();
        $manager->givePermissionTo('purchasing.over-receipt-policy.manage');
        $this->actingAs($manager)
            ->patchJson('/api/v1/purchasing-settings', ['over_receipt_mode' => 'ALLOW_WITHIN_TOLERANCE'])
            ->assertUnprocessable();

        $this->actingAs($manager)
            ->patchJson('/api/v1/purchasing-settings', ['over_receipt_mode' => 'ALLOW_WITHIN_TOLERANCE', 'reason' => 'Agreed 5% band with the mill'])
            ->assertSuccessful();

        $change = PurchasingPolicyChange::query()->sole();
        $this->assertSame('OVER_RECEIPT', $change->policy);
        $this->assertSame('BLOCK', $change->old_values['over_receipt_mode']);
        $this->assertSame('ALLOW_WITHIN_TOLERANCE', $change->new_values['over_receipt_mode']);
        $this->assertSame($manager->id, $change->changed_by);

        $this->expectException(DomainInvariantException::class);
        $change->update(['reason' => 'rewritten']);
    }

    public function test_index_search_filters_by_partial_number(): void
    {
        [$orderA, $itemA, $uomA] = $this->makeOrder();
        $orderA->lines()->create($this->lineAttributes($orderA, $itemA, $uomA));
        [$orderB, $itemB, $uomB] = $this->makeOrderB();
        $orderB->lines()->create($this->lineAttributes($orderB, $itemB, $uomB));
        $user = User::factory()->create();

        $numberA = $this->actingAs($user)->postJson("/api/v1/purchase-orders/{$orderA->id}/issue")->assertOk()->json('data.number');
        $numberB = $this->actingAs($user)->postJson("/api/v1/purchase-orders/{$orderB->id}/issue")->assertOk()->json('data.number');
        $this->assertNotSame($numberA, $numberB);

        $this->getJson('/api/v1/purchase-orders?filter[search]='.$numberB)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orderB->id);
    }

    /**
     * Phase 2 integrity audit: "an issued PO remains historically unchanged" is
     * enforced by the database (purchase_orders_guard/purchase_order_lines_guard),
     * not only by the models' updating() guards — raw SQL cannot rewrite issued
     * commercial data or line snapshots, while the columns issue/short-close/
     * cancel legitimately change stay writable.
     */
    public function test_issued_order_commercial_data_and_line_snapshots_are_database_immutable(): void
    {
        [$order, $item, $uom] = $this->makeOrder();
        $line = $order->lines()->create($this->lineAttributes($order, $item, $uom));
        $this->actingAs(User::factory()->create())->postJson("/api/v1/purchase-orders/{$order->id}/issue")->assertOk();

        $this->assertDatabaseRejects(fn () => DB::table('purchase_orders')->where('id', $order->id)->update(['order_date' => '2026-09-03']));
        $this->assertDatabaseRejects(fn () => DB::table('purchase_order_lines')->where('id', $line->id)->update(['unit_price' => '1.0000']));
        $this->assertDatabaseRejects(fn () => DB::table('purchase_order_lines')->where('id', $line->id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('purchase_orders')->where('id', $order->id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('purchase_order_lines')->insert(array_merge(
            (array) DB::table('purchase_order_lines')->where('id', $line->id)->first(), ['id' => $line->id + 1000, 'line_number' => 99],
        )));

        DB::table('purchase_order_lines')->where('id', $line->id)->update(['short_closed_stock_qty' => '1.0000']);
        DB::table('purchase_orders')->where('id', $order->id)->update(['cancellation_reason' => 'raw but permitted']);
        $this->assertDatabaseHas('purchase_order_lines', ['id' => $line->id, 'short_closed_stock_qty' => '1.0000']);
    }

    private function assertDatabaseRejects(callable $mutation): void
    {
        try {
            DB::transaction($mutation);
        } catch (QueryException $e) {
            $this->assertSame('23514', (string) $e->getCode());

            return;
        }

        $this->fail('Expected the database to reject the mutation.');
    }

    public function test_draft_creation_calculates_stock_and_money_and_validates_smallint(): void
    {
        [$template, $item, $uom] = $this->makeOrder();
        $user = User::factory()->create();
        $payload = $this->payload($template, $item->id, $uom->id);

        $this->actingAs($user)->postJson('/api/v1/purchase-orders', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.lines.0.ordered_stock_qty', '2.0000')
            ->assertJsonPath('data.lines.0.line_amount', '20.0000')
            ->assertJsonPath('data.total_amount', '20.0000')
            ->assertJsonPath('data.base_total_amount', '20.0000');

        $payload['lines'][0]['line_number'] = 32768;
        $this->postJson('/api/v1/purchase-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.line_number');
    }

    public function test_draft_header_can_be_edited_and_fx_derived_line_amounts_are_recalculated(): void
    {
        [$order, $item, $uom] = $this->makeOrder();
        $line = $order->lines()->create(array_merge($this->lineAttributes($order, $item, $uom), [
            'discount_amount' => '1.0000',
            'tax_amount' => '0.5000',
            'line_amount' => '19.5000',
            'base_discount_amount' => '1.0000',
            'base_tax_amount' => '0.5000',
            'base_line_amount' => '19.5000',
        ]));
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson("/api/v1/purchase-orders/{$order->id}", [
            'fx_rate' => '1.500000000',
        ])->assertOk()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.fx_rate', '1.500000000')
            ->assertJsonPath('data.lines.0.base_unit_price', '15.0000')
            ->assertJsonPath('data.lines.0.base_discount_amount', '1.5000')
            ->assertJsonPath('data.lines.0.base_tax_amount', '0.7500')
            ->assertJsonPath('data.lines.0.base_line_amount', '29.2500');

        $this->assertSame($line->id, $order->lines()->sole()->id);
    }

    public function test_purchase_uom_with_a_different_dimension_returns_validation_error(): void
    {
        [$template, $item, $uom] = $this->makeOrder();
        $volumeUom = Uom::create([
            'code' => 'l',
            'name' => 'Litre',
            'dimension' => UomDimension::Volume,
            'factor_to_base' => '1',
        ]);
        $payload = $this->payload($template, $item->id, $uom->id);
        $payload['lines'][0]['purchase_uom_id'] = $volumeUom->id;

        $this->actingAs(User::factory()->create())->postJson('/api/v1/purchase-orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purchase_uom_id');
    }

    public function test_item_specific_pack_conversion_resolves_first_and_may_cross_uom_dimension(): void
    {
        // ADR 0050's own worked example: a roll (COUNT) is sold as a pack of
        // an item stocked by the metre (LENGTH) — the global, dimension-locked
        // service could never convert this; only an item_quantity_units
        // override for this exact item can.
        [$template] = $this->makeOrder();
        $metre = Uom::create(['code' => 'm-pack', 'name' => 'Metre', 'dimension' => UomDimension::Length, 'factor_to_base' => '1']);
        $roll = Uom::create(['code' => 'roll', 'name' => 'Roll', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'fabric', 'name' => 'Fabric', 'default_valuation_method' => 'AVCO']);
        $item = Item::create(['code' => 'roll-item', 'name' => 'Rolled fabric', 'type' => 'RAW', 'stock_uom_id' => $metre->id, 'category_id' => $category->id]);
        $item->quantityUnits()->create(['uom_id' => $roll->id, 'factor' => '50']);

        $payload = $this->payload($template, $item->id, $roll->id);
        $payload['lines'][0]['ordered_qty'] = '2.0000';

        $this->actingAs(User::factory()->create())->postJson('/api/v1/purchase-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.lines.0.ordered_stock_qty', '100.0000')
            ->assertJsonPath('data.lines.0.stock_uom_id', $metre->id)
            ->assertJsonPath('data.lines.0.uom_factor', '50.000000000');
    }

    public function test_base_line_amount_converts_the_rounded_transaction_line_total(): void
    {
        [$template, $item, $uom] = $this->makeOrder();
        $payload = $this->payload($template, $item->id, $uom->id);
        $payload['fx_rate'] = '1.234567890';
        $payload['lines'][0] = array_merge($payload['lines'][0], [
            'ordered_qty' => '3.0000',
            'unit_price' => '0.3333',
            'discount_amount' => '0.0001',
            'tax_amount' => '0.0001',
        ]);

        $this->actingAs(User::factory()->create())->postJson('/api/v1/purchase-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.lines.0.line_amount', '0.9999')
            ->assertJsonPath('data.lines.0.base_line_amount', '1.2344');
    }

    public function test_issue_snapshots_policy_item_and_supplier_data_then_blocks_editing(): void
    {
        [$order, $item, $uom] = $this->makeOrder();
        $user = User::factory()->create();
        $order->lines()->create($this->lineAttributes($order, $item, $uom));
        ItemSupplier::create(['item_id' => $item->id, 'supplier_account_id' => $order->supplier_account_id, 'supplier_item_code' => 'SUP-SKU']);
        PurchasingSetting::create(['over_receipt_mode' => OverReceiptMode::AllowWithinTolerance, 'over_receipt_tolerance_percent' => '5.0000']);

        $this->actingAs($user)->postJson("/api/v1/purchase-orders/{$order->id}/issue")
            ->assertOk()->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.number', '0001')->assertJsonPath('data.lines.0.item_code', 'item')
            ->assertJsonPath('data.lines.0.supplier_item_code', 'SUP-SKU')
            ->assertJsonPath('data.lines.0.over_receipt_mode', 'ALLOW_WITHIN_TOLERANCE')
            ->assertJsonPath('data.lines.0.over_receipt_tolerance_percent', '5.0000');

        $this->patchJson("/api/v1/purchase-orders/{$order->id}", ['order_date' => '2026-09-03'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_short_closing_the_final_issued_line_closes_the_order(): void
    {
        [$order, $item, $uom] = $this->makeOrder();
        $line = $order->lines()->create($this->lineAttributes($order, $item, $uom));
        $user = User::factory()->create();
        $this->actingAs($user)->postJson("/api/v1/purchase-orders/{$order->id}/issue")
            ->assertOk()->assertJsonPath('data.lines.0.over_receipt_mode', 'BLOCK')
            ->assertJsonPath('data.lines.0.over_receipt_tolerance_percent', '0.0000');
        $this->postJson("/api/v1/purchase-order-lines/{$line->id}/short-close")
            ->assertOk()->assertJsonPath('data.short_closed_stock_qty', '2.0000');

        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'CLOSED']);
    }

    public function test_issued_order_can_be_cancelled_with_reason(): void
    {
        [$order, $item, $uom] = $this->makeOrder();
        $order->lines()->create($this->lineAttributes($order, $item, $uom));
        $user = User::factory()->create();
        $this->actingAs($user)->postJson("/api/v1/purchase-orders/{$order->id}/issue")->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$order->id}/cancel", ['reason' => 'Supplier cannot fulfil'])
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.cancellation_reason', 'Supplier cannot fulfil');
    }

    /**
     * A second, independently-coded order/item/uom/supplier — makeOrder() reuses
     * PurchaseOrderFoundationTest's hardcoded 'SUP'/'ea'/'raw'/'item' codes, which
     * collide on a second call within the same test.
     *
     * @return array{PurchaseOrder, Item, Uom}
     */
    private function makeOrderB(): array
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        $party = Party::create(['code' => 'SUP2', 'name' => 'SUP2']);
        $account = ChartOfAccount::create(['code' => 'pay-SUP2', 'name' => 'Payable SUP2', 'type' => AccountType::Liability]);
        $supplier = SupplierAccount::create(['party_id' => $party->id, 'code' => 'SUP2', 'payment_terms_days' => 30, 'payable_account_id' => $account->id]);
        $partySite = PartySite::create(['party_id' => $party->id, 'code' => 'main', 'name' => 'Main']);
        $site = SupplierSite::create(['supplier_account_id' => $supplier->id, 'party_site_id' => $partySite->id, 'role' => 'ORDER_FROM']);
        $uom = Uom::create(['code' => 'ea2', 'name' => 'Each 2', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw2', 'name' => 'Raw 2', 'default_valuation_method' => 'AVCO']);
        $item = Item::create(['code' => 'item2', 'name' => 'Item 2', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id]);
        $order = PurchaseOrder::create([
            'supplier_account_id' => $supplier->id, 'supplier_site_id' => $site->id,
            'currency_id' => $currency->id, 'fx_rate' => '1.000000000',
            'fx_rate_date' => '2026-09-02', 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => '2026-09-02',
        ])->refresh();

        return [$order, $item, $uom];
    }

    /** @return array<string, mixed> */
    private function payload($order, int $itemId, int $uomId): array
    {
        return ['supplier_account_id' => $order->supplier_account_id, 'supplier_site_id' => $order->supplier_site_id, 'currency_id' => $order->currency_id, 'fx_rate' => '1.000000000', 'fx_rate_date' => '2026-09-02', 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => '2026-09-02', 'lines' => [['line_number' => 1, 'item_id' => $itemId, 'description' => 'Item', 'ordered_qty' => '2.0000', 'purchase_uom_id' => $uomId, 'unit_price' => '10.0000']]];
    }

    public function test_search_matches_supplier_party_item_sku_and_source_record_but_not_notes(): void
    {
        [$orderA, $itemA, $uomA] = $this->makeOrder();
        $orderA->update([
            'notes' => 'SecretSpecialRemark',
        ]);
        $orderA->lines()->create(array_merge($this->lineAttributes($orderA, $itemA, $uomA), [
            'item_code' => 'PIG-BLUE',
            'description' => 'Blue Phthalo Pigment',
            'supplier_item_code' => 'SKU-777',
        ]));

        [$orderB, $itemB, $uomB] = $this->makeOrderB();
        $orderB->lines()->create(array_merge($this->lineAttributes($orderB, $itemB, $uomB), [
            'item_code' => 'RESIN-CLEAR',
            'description' => 'Clear Epoxy Resin',
        ]));

        $buyer = PurchasingActor::make([
            'purchasing.purchase-order.create',
            'purchasing.purchase-order.view',
        ]);

        // 1. Match by supplier account code
        $this->actingAsUnprivileged($buyer)
            ->getJson('/api/v1/purchase-orders?filter[search]=SUP2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orderB->id);

        // 2. Match by supplier party name
        $orderB->supplierAccount->party->update(['name' => 'Acme Chemicals International']);
        $this->getJson('/api/v1/purchase-orders?filter[search]=Acme+Chemicals')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orderB->id);

        // 3. Match by line item code
        $this->getJson('/api/v1/purchase-orders?filter[search]=PIG-BLUE')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orderA->id);

        // 4. Match by line description
        $this->getJson('/api/v1/purchase-orders?filter[search]=Phthalo')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orderA->id);

        // 5. Match by supplier item SKU
        $this->getJson('/api/v1/purchase-orders?filter[search]=SKU-777')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orderA->id);

        // 6. Notes are deliberately excluded from global search for predictability
        $this->getJson('/api/v1/purchase-orders?filter[search]=SecretSpecialRemark')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_advanced_filters_for_dates_item_site_currency_and_value_ranges(): void
    {
        [$orderA, $itemA, $uomA] = $this->makeOrder();
        $orderA->update([
            'order_date' => '2026-09-05',
            'expected_delivery_date' => '2026-09-15',
        ]);
        $orderA->lines()->create(array_merge($this->lineAttributes($orderA, $itemA, $uomA), [
            'ordered_qty' => '10.0000',
            'unit_price' => '100.0000',
            'line_amount' => '1000.0000',
        ]));

        [$orderB, $itemB, $uomB] = $this->makeOrderB();
        $orderB->update([
            'order_date' => '2026-09-20',
            'expected_delivery_date' => '2026-09-25',
        ]);
        $orderB->lines()->create(array_merge($this->lineAttributes($orderB, $itemB, $uomB), [
            'ordered_qty' => '50.0000',
            'unit_price' => '100.0000',
            'line_amount' => '5000.0000',
        ]));

        $buyer = PurchasingActor::make([
            'purchasing.purchase-order.create',
            'purchasing.purchase-order.view',
            'purchasing.commercial-cost.view',
        ]);

        // Order date range
        $this->actingAsUnprivileged($buyer)
            ->getJson('/api/v1/purchase-orders?filter[order_date_from]=2026-09-01&filter[order_date_to]=2026-09-10')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orderA->id);

        // Expected delivery range
        $this->getJson('/api/v1/purchase-orders?filter[expected_delivery_from]=2026-09-20&filter[expected_delivery_to]=2026-09-30')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orderB->id);

        // Validation rejects order_date_to before order_date_from
        $this->getJson('/api/v1/purchase-orders?filter[order_date_from]=2026-09-20&filter[order_date_to]=2026-09-10')
            ->assertUnprocessable();

        // Item filter
        $this->getJson("/api/v1/purchase-orders?filter[item_id]={$itemA->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orderA->id);
        $this->getJson("/api/v1/purchase-orders?filter[item_id]={$itemB->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orderB->id);

        // Supplier site filter
        $this->getJson("/api/v1/purchase-orders?filter[supplier_site_id]={$orderA->supplier_site_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orderA->id);

        // Total amount min/max range
        $this->getJson('/api/v1/purchase-orders?filter[total_min]=2000&filter[total_max]=6000')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orderB->id);
        $this->getJson('/api/v1/purchase-orders?filter[total_max]=1500')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $orderA->id);

        // Validation rejects total_max less than total_min
        $this->getJson('/api/v1/purchase-orders?filter[total_min]=5000&filter[total_max]=1000')
            ->assertUnprocessable();
    }

    public function test_total_amount_filters_require_commercial_cost_permission(): void
    {
        [$order, $item, $uom] = $this->makeOrder();
        $order->lines()->create($this->lineAttributes($order, $item, $uom));

        // Viewer with only purchase-order.view capability (no commercial cost access)
        $viewer = PurchasingActor::make(['purchasing.purchase-order.view']);

        $this->actingAsUnprivileged($viewer)
            ->getJson('/api/v1/purchase-orders?filter[total_min]=100')
            ->assertForbidden();

        $this->getJson('/api/v1/purchase-orders?filter[total_max]=500')
            ->assertForbidden();

        // Grant commercial cost capability
        PurchasingActor::grant($viewer, ['purchasing.commercial-cost.view']);

        $this->getJson('/api/v1/purchase-orders?filter[total_min]=100')
            ->assertOk();
    }
}
