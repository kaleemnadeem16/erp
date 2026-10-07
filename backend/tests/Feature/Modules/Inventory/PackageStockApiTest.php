<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Inventory\Http\Requests\PackageStock\ShowItemPackageTrackingRequest;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Support\CompanyContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;

/**
 * The HTTP layer of grouped package tracking (docs/phases/package-balance-ui.md): the package balance read, item tracking state, the activation and
 * opening-count endpoints, draw suggestion, the grouped stock issue and the receipt composition. XYZ Paints: 6 L at 8.00 and 14 L at 10.00 = 20 L.
 */
class PackageStockApiTest extends RecordPackageCheckpointTest
{
    /** @var array<string, mixed> */
    private array $w = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function world(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo, qty: '60.0000');
        $a = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '6', '8', 99101);
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '14', '10', 99102);
        $can = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'can', 'name' => '5 L can', 'stock_quantity' => '5', 'active' => true]);
        $manager = PurchasingActor::make(['inventory.package-state.view', 'inventory.package-state.activate', 'inventory.physical_count.create', 'inventory.package-state.issue', 'inventory.package-state.open',
            'purchasing.goods-receipt.create', 'purchasing.goods-receipt.post']);
        $this->w = ['item' => $item, 'unit' => $unit, 'a' => $a, 'can' => $can, 'manager' => $manager, 'order' => $order];
    }

    /** @return array<string, mixed> */
    private function activate(string $suffix = '1'): array
    {
        $checkpoint = $this->actingAs($this->w['manager'])->postJson('/api/v1/package-checkpoints', [
            'item_id' => $this->w['item']->id, 'location_id' => $this->w['a'], 'request_key' => 'count-'.$suffix, 'reason' => 'Opening count', 'expected_quantity' => '20',
            'composition' => [['packaging_id' => $this->w['can']->id, 'count' => 3], ['quantity' => '5']],
        ])->assertCreated()->json('data.operation_id');
        $this->postJson("/api/v1/items/{$this->w['item']->id}/package-tracking/activate", ['request_key' => 'act-'.$suffix, 'reason' => 'Start', 'checkpoints' => [$checkpoint]])
            ->assertCreated()->assertJsonPath('data.mode', 'PACKAGE_BALANCE');

        return ['checkpoint' => $checkpoint];
    }

    public function test_a_quantity_only_item_reports_its_state_its_packages_and_an_honest_unevidenced_balance(): void
    {
        $this->world();
        $this->actingAs($this->w['manager']);

        $this->getJson("/api/v1/items/{$this->w['item']->id}")->assertOk()->assertJsonPath('data.package_tracking_mode', 'QUANTITY_ONLY');
        $state = $this->getJson("/api/v1/items/{$this->w['item']->id}/package-tracking")->assertOk()->assertJsonPath('data.mode', 'QUANTITY_ONLY')->assertJsonPath('data.can_activate', true);
        $this->assertSame(['can'], array_column($state->json('data.packagings'), 'code'));
        $balance = $this->getJson("/api/v1/package-balances?item_id={$this->w['item']->id}")->assertOk()->json('data.0');
        $this->assertSame(['20.0000', false, ['NO_EVIDENCE']], [$balance['total'], $balance['fresh'], $balance['reasons']]);
    }

    public function test_activation_runs_end_to_end_over_http_and_the_balance_shows_the_breakdown(): void
    {
        $this->world();
        $this->actingAs($this->w['manager']);
        $this->activate();

        $this->getJson("/api/v1/items/{$this->w['item']->id}")->assertJsonPath('data.package_tracking_mode', 'PACKAGE_BALANCE');
        $tracking = $this->getJson("/api/v1/items/{$this->w['item']->id}/package-tracking")->assertJsonPath('data.mode', 'PACKAGE_BALANCE')->assertJsonPath('data.can_activate', false);
        $this->assertSame(['can'], array_column($tracking->json('data.tracked_levels'), 'code'));
        $balance = $this->getJson("/api/v1/package-balances?item_id={$this->w['item']->id}&location_id={$this->w['a']}")->assertOk()->json('data.0');
        $this->assertSame(['20.0000', true, '5.0000', 3], [$balance['total'], $balance['fresh'], $balance['loose'], $balance['sealed'][0]['count']]);
    }

    public function test_the_activation_preview_names_blockers_per_scope_and_a_stale_count_is_refused_with_its_reason(): void
    {
        $this->world();
        $this->actingAs($this->w['manager']);
        $preview = $this->postJson("/api/v1/items/{$this->w['item']->id}/package-tracking/preview", ['checkpoints' => []])->assertOk();
        $this->assertFalse($preview->json('data.ready'));
        $this->assertContains('A fresh counted checkpoint is required.', $preview->json('data.scopes.0.problems'));

        $this->postJson('/api/v1/package-checkpoints', ['item_id' => $this->w['item']->id, 'location_id' => $this->w['a'], 'request_key' => 'bad', 'reason' => 'Count',
            'expected_quantity' => '19', 'composition' => [['quantity' => '19']]])->assertUnprocessable();
        $this->postJson("/api/v1/items/{$this->w['item']->id}/package-tracking/activate", ['request_key' => 'act', 'reason' => 'Start', 'checkpoints' => [999999]])
            ->assertUnprocessable()->assertJsonPath('reason_code', 'ACTIVATION_BLOCKED');
        $this->assertSame('QUANTITY_ONLY', $this->w['item']->fresh()->package_tracking_mode->value);
    }

    public function test_every_endpoint_needs_its_capability(): void
    {
        $this->world();
        $nobody = PurchasingActor::make([]);
        $item = $this->w['item']->id;
        $this->actingAs($nobody);
        $this->getJson("/api/v1/package-balances?item_id={$item}")->assertForbidden();
        $this->postJson("/api/v1/items/{$item}/package-tracking/preview", [])->assertForbidden();
        $this->postJson("/api/v1/items/{$item}/package-tracking/activate", ['request_key' => 'k', 'reason' => 'r', 'checkpoints' => [1]])->assertForbidden();
        $this->postJson('/api/v1/package-checkpoints', ['item_id' => $item, 'location_id' => 1, 'request_key' => 'k', 'reason' => 'r', 'expected_quantity' => '1', 'composition' => [['quantity' => '1']]])->assertForbidden();
        $this->postJson('/api/v1/package-draws/suggest', ['item_id' => $item, 'location_id' => 1, 'quantity' => '1'])->assertForbidden();
        $this->postJson('/api/v1/stock-issues', ['item_id' => $item, 'location_id' => 1, 'request_key' => 'k', 'reason' => 'r', 'draw' => [['kind' => 'LOOSE', 'quantity' => '1']]])->assertForbidden();
    }

    public function test_the_suggestion_takes_loose_first_then_whole_cans_then_opens_one_for_the_rest(): void
    {
        $this->world();
        $this->actingAs($this->w['manager']);
        $this->activate();
        $base = ['item_id' => $this->w['item']->id, 'location_id' => $this->w['a']];

        $twelve = $this->postJson('/api/v1/package-draws/suggest', $base + ['quantity' => '12'])->assertOk();
        $this->assertSame(['LOOSE', 'SEALED', 'OPEN_AND_DRAW'], array_column($twelve->json('data.lines'), 'kind'));
        $this->assertTrue($twelve->json('data.requires_opening'));
        $this->assertSame(['1.0000' === '1.0000', '3.0000', '8.0000'], [true, $twelve->json('data.leaves.loose'), $twelve->json('data.leaves.total')]);
        $this->assertSame(1, $twelve->json('data.leaves.sealed.0.count'));

        $five = $this->postJson('/api/v1/package-draws/suggest', $base + ['quantity' => '5'])->assertOk();
        $this->assertSame(['LOOSE'], array_column($five->json('data.lines'), 'kind'));
        $this->assertFalse($five->json('data.requires_opening'));

        $this->postJson('/api/v1/package-draws/suggest', $base + ['quantity' => '25'])->assertUnprocessable()->assertJsonPath('reason_code', 'DRAW_NOT_ON_HAND');
    }

    public function test_a_grouped_issue_posts_the_draw_returns_the_new_breakdown_and_is_idempotent(): void
    {
        $this->world();
        $this->actingAs($this->w['manager']);
        $this->activate();
        $payload = ['item_id' => $this->w['item']->id, 'location_id' => $this->w['a'], 'request_key' => 'issue-1', 'reason' => 'Job 1042', 'reference' => 'JOB-1042', 'draw' => [
            ['kind' => 'LOOSE', 'quantity' => '5'], ['kind' => 'SEALED', 'packaging_id' => $this->w['can']->id, 'count' => 1],
            ['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $this->w['can']->id, 'count' => 1, 'quantity' => '2'],
        ]];

        $first = $this->postJson('/api/v1/stock-issues', $payload)->assertCreated();
        $this->assertSame(['12.0000', 1, '3.0000', '8.0000', true], [$first->json('data.quantity'), $first->json('data.balance.sealed.0.count'),
            $first->json('data.balance.loose'), $first->json('data.balance.total'), $first->json('data.balance.fresh')]);
        $moves = StockMove::query()->count();

        $retry = $this->postJson('/api/v1/stock-issues', $payload)->assertCreated();
        $this->assertSame($first->json('data.operation_id'), $retry->json('data.operation_id'));
        $this->assertSame($moves, StockMove::query()->count());
        $this->assertSame('ISSUE', PackageOperation::query()->findOrFail($first->json('data.operation_id'))->evidence_kind);
    }

    public function test_a_draw_that_is_not_on_hand_or_a_location_without_a_count_is_refused_with_a_reason_code(): void
    {
        $this->world();
        $this->actingAs($this->w['manager']);
        $payload = ['item_id' => $this->w['item']->id, 'location_id' => $this->w['a'], 'request_key' => 'early', 'reason' => 'Job', 'draw' => [['kind' => 'LOOSE', 'quantity' => '1']]];
        $this->postJson('/api/v1/stock-issues', $payload)->assertUnprocessable()->assertJsonPath('reason_code', 'NEEDS_COUNT_FIRST');

        $this->activate();
        $this->postJson('/api/v1/stock-issues', array_replace($payload, ['request_key' => 'too-much', 'draw' => [['kind' => 'LOOSE', 'quantity' => '9']]]))
            ->assertUnprocessable()->assertJsonPath('reason_code', 'DRAW_NOT_ON_HAND');
        $this->postJson('/api/v1/stock-issues', array_replace($payload, ['request_key' => 'wrong-level', 'draw' => [['kind' => 'SEALED', 'packaging_id' => 999999, 'count' => 1]]]))->assertUnprocessable();
    }

    public function test_the_receipt_composition_is_recorded_over_http_and_shown_on_the_line(): void
    {
        $this->world();
        $this->actingAs($this->w['manager']);
        $this->activate();
        $receipt = $this->draftReceipt('12');
        $line = $receipt->lines()->sole()->id;

        $this->putJson("/api/v1/goods-receipt-lines/{$line}/package-composition", ['reason' => 'Counted on arrival', 'composition' => [['packaging_id' => $this->w['can']->id, 'count' => 1]]])
            ->assertUnprocessable();
        $this->putJson("/api/v1/goods-receipt-lines/{$line}/package-composition", ['reason' => 'Counted on arrival', 'composition' => [
            ['packaging_id' => $this->w['can']->id, 'count' => 2], ['quantity' => '2'],
        ]])->assertOk()->assertJsonPath('data.package_composition.0.sealed_count', 2)->assertJsonPath('data.package_composition.1.stock_quantity', '2.0000');
        $this->getJson("/api/v1/goods-receipts/{$receipt->id}")->assertOk()->assertJsonCount(2, 'data.lines.0.package_composition');
        $this->postJson("/api/v1/goods-receipts/{$receipt->id}/post")->assertOk();
        $this->assertSame('RECEIPT_IN', PackageOperation::query()->orderByDesc('id')->firstOrFail()->evidence_kind);
    }

    public function test_balances_follow_the_callers_warehouse_scope(): void
    {
        $this->world();
        $this->actingAs($this->w['manager']);
        $this->activate();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $role = Role::create(['team_id' => 1, 'name' => 'Limited viewer', 'guard_name' => 'web']);
        Permission::findOrCreate('inventory.package-state.view', 'web');
        $role->givePermissionTo('inventory.package-state.view');
        $limited = User::factory()->create(['company_id' => 1]);
        $limited->assignRole($role);
        AccessScopeLimit::create(['user_id' => $limited->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS']);

        $this->actingAs($limited)->getJson("/api/v1/package-balances?item_id={$this->w['item']->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    private function draftReceipt(string $quantity): GoodsReceipt
    {
        return app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $this->w['order']->supplier_account_id, 'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [['purchase_order_line_id' => $this->w['order']->lines()->sole()->id, 'item_id' => $this->w['item']->id, 'location_id' => $this->w['a'],
                'received_qty' => $quantity, 'received_uom_id' => $this->w['unit']->id]],
        ], $this->w['manager']);
    }

    public function test_another_companys_item_is_not_visible_through_any_package_endpoint(): void
    {
        $this->world();
        $this->actingAs($this->w['manager']);
        $this->activate();
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->getJson("/api/v1/items/{$this->w['item']->id}/package-tracking")->assertNotFound();
        $this->assertContains($this->getJson("/api/v1/package-balances?item_id={$this->w['item']->id}")->getStatusCode(), [404, 422], 'nothing of another company is disclosed');
    }

    public function test_reading_the_package_structure_needs_one_of_the_package_receipt_or_count_capabilities(): void
    {
        $request = ShowItemPackageTrackingRequest::create('/', 'GET');
        $bare = User::factory()->create(['company_id' => 1]);
        $request->setUserResolver(fn () => $bare);
        $this->assertFalse($request->authorize());

        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.package-state.issue', 'web');
        $bare->givePermissionTo('inventory.package-state.issue');
        $this->assertTrue($request->authorize());
    }
}
