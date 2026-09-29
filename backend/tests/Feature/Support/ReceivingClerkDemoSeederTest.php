<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Purchasing\Models\GoodsReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The demo Receiving Clerk (ADR 0069) sees a quantity-only projection, while their draft receipt carries the
 * cost the server derived from the issued purchase order — visible to someone with commercial visibility.
 */
final class ReceivingClerkDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
    }

    public function test_the_clerk_sees_no_price_cost_or_fx_and_the_admin_sees_the_derived_cost(): void
    {
        $receipt = GoodsReceipt::query()->where('idempotency_key', 'demo-receipt-thinner-clerk-draft')->sole();
        $clerk = User::query()->where('email', 'demo-receiver@erp.x1techs.com')->sole();
        $admin = User::query()->where('email', config('demo.user.email'))->sole();

        $seen = $this->actingAs($clerk)->getJson("/api/v1/goods-receipts/{$receipt->id}")->assertOk()->json('data.lines.0');
        foreach (['provisional_unit_cost', 'base_unit_cost', 'fx_rate', 'fx_rate_date', 'fx_rate_type', 'fx_rate_source'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $seen);
        }
        $this->assertSame('40.0000', $seen['received_qty']);

        $order = $this->getJson('/api/v1/purchase-orders?per_page=50')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('total_amount', $order);

        $full = $this->actingAs($admin)->getJson("/api/v1/goods-receipts/{$receipt->id}")->assertOk()->json('data.lines.0');
        $this->assertSame('348.0000', $full['provisional_unit_cost']);
        $this->assertSame('1.000000000', $full['fx_rate']);
    }

    public function test_the_clerk_cannot_override_the_derived_cost_through_the_api(): void
    {
        $receipt = GoodsReceipt::query()->where('idempotency_key', 'demo-receipt-thinner-clerk-draft')->with('lines')->sole();
        $line = $receipt->lines->sole();
        $clerk = User::query()->where('email', 'demo-receiver@erp.x1techs.com')->sole();

        $this->actingAs($clerk)->postJson('/api/v1/goods-receipts', [
            'supplier_account_id' => $receipt->supplier_account_id,
            'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [[
                'purchase_order_line_id' => $line->purchase_order_line_id, 'item_id' => $line->item_id, 'location_id' => $line->location_id,
                'received_qty' => '1.0000', 'received_uom_id' => $line->received_uom_id, 'provisional_unit_cost' => '1.0000',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.provisional_unit_cost');
    }
}
