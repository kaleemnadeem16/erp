<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Models\User;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Modules\Purchasing\Models\PurchasingSetting;
use App\Modules\Purchasing\Support\OverReceiptMode;

class PurchaseOrderApiTest extends PurchaseOrderFoundationTest
{
    public function test_purchasing_settings_are_configurable_and_purchase_order_queries_are_validated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/purchasing-settings')
            ->assertOk()->assertJsonPath('data.over_receipt_mode', 'BLOCK');
        $this->patchJson('/api/v1/purchasing-settings', ['over_receipt_mode' => 'ALLOW_WITHIN_TOLERANCE', 'over_receipt_tolerance_percent' => '7.5000'])
            ->assertOk()->assertJsonPath('data.over_receipt_tolerance_percent', '7.5000');
        $this->getJson('/api/v1/purchase-orders?sort=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/purchase-orders?include=unknown')->assertUnprocessable();
    }

    public function test_draft_creation_calculates_stock_and_money_and_validates_smallint(): void
    {
        [$template, $item, $uom] = $this->makeOrder();
        $user = User::factory()->create();
        $payload = $this->payload($template, $item->id, $uom->id);

        $this->actingAs($user)->postJson('/api/v1/purchase-orders', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.lines.0.ordered_stock_qty', '2.0000')
            ->assertJsonPath('data.lines.0.line_amount', '20.0000');

        $payload['lines'][0]['line_number'] = 32768;
        $this->postJson('/api/v1/purchase-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.line_number');
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

    /** @return array<string, mixed> */
    private function payload($order, int $itemId, int $uomId): array
    {
        return ['supplier_account_id' => $order->supplier_account_id, 'supplier_site_id' => $order->supplier_site_id, 'currency_id' => $order->currency_id, 'fx_rate' => '1.000000000', 'fx_rate_date' => '2026-09-02', 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => '2026-09-02', 'lines' => [['line_number' => 1, 'item_id' => $itemId, 'description' => 'Item', 'ordered_qty' => '2.0000', 'purchase_uom_id' => $uomId, 'unit_price' => '10.0000']]];
    }
}
