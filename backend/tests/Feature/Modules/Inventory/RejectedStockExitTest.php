<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Inventory\Actions\ActivateGroupedPackageTracking;
use App\Modules\Inventory\Actions\IssueGroupedStock;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\PostRejectedStockExit;
use App\Modules\Inventory\Actions\ReadPackageBalances;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\RejectedStockExit;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\PackageWriteScope;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Actions\RecordReceiptComposition;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;

/**
 * ADR 0097, slice 1: the shared exit core. Rejected stock — and only rejected stock — leaves for good from its own delivery layer, at the layer's cost (FIFO/SPECIFIC)
 * or the pool average (AVCO), with whole rejected packages removed for a grouped item and no package evidence for a quantity-only one. XYZ Paints: 20 L usable
 * (3 cans + 5 L loose) and a QA-required delivery of 12 L (2 cans + 2 L loose).
 */
class RejectedStockExitTest extends RecordPackageCheckpointTest
{
    /** @var array<string, mixed> */
    private array $world = [];

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

    private function grouped(ValuationMethod $method = ValuationMethod::Fifo): void
    {
        [$order, $item, $unit] = $this->issuedOrder($method, qty: '60.0000', qaRequired: true);
        $a = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '6', '8', 99101);
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '14', '10', 99102);
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['inventory.physical_count.create', 'inventory.package-state.view', 'inventory.package-state.open', 'inventory.package-state.issue', 'inventory.package-state.activate',
            'purchasing.quality-disposition.post']);
        $can = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'can', 'name' => '5 L can', 'stock_quantity' => '5', 'active' => true]);
        $count = app(RecordPackageCheckpoint::class)([
            'item_id' => $item->id, 'location_id' => $a, 'request_key' => 'xyz-count', 'reason' => 'Opening count', 'expected_quantity' => '20',
            'expected_layers' => StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all(), 'composition' => [['packaging_id' => $can->id, 'count' => 3], ['quantity' => '5']],
        ], $actor);
        app(ActivateGroupedPackageTracking::class)(['item_id' => $item->id, 'request_key' => 'activate', 'reason' => 'Start', 'checkpoints' => [$count->id]], $actor);
        $this->world = ['item' => $item, 'unit' => $unit, 'a' => $a, 'can' => $can, 'actor' => $actor, 'order' => $order, 'exiter' => $this->exiter()];
    }

    private function exiter(): User
    {
        return PurchasingActor::make(['purchasing.supplier-return.post', 'inventory.scrap.post', 'inventory.package-state.view']);
    }

    private function receipt(string $qty, ?array $composition = null): GoodsReceipt
    {
        $receipt = app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $this->world['order']->supplier_account_id, 'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [['purchase_order_line_id' => $this->world['order']->lines()->sole()->id, 'item_id' => $this->world['item']->id, 'location_id' => $this->world['a'],
                'received_qty' => $qty, 'received_uom_id' => $this->world['unit']->id]],
        ], $this->world['actor']);
        if ($composition !== null) {
            app(RecordReceiptComposition::class)($receipt->lines()->sole(), $composition, 'Counted on arrival', $this->world['actor']);
        }

        return app(PostGoodsReceipt::class)($receipt, $this->world['actor']);
    }

    /** @param list<array<string,mixed>> $portion */
    private function dispose(GoodsReceipt $receipt, string $outcome, string $qty, array $portion = []): void
    {
        $attributes = ['disposition' => $outcome, 'qty' => $qty, 'reason' => 'Inspected'];
        if ($portion !== []) {
            $attributes['package_portion'] = $portion;
        }
        app(PostQualityDisposition::class)($receipt->lines()->sole(), $attributes, $this->world['actor']->id);
        // Each disposition commits in its own transaction in production; prove its commit-time checks before the next one lands.
        $this->flush();
    }

    /** The 12 L delivery: 2 L passed (usable), one can rejected for return, one can still held. */
    private function mixedDelivery(string $rejectOutcome = 'RETURN_TO_SUPPLIER'): GoodsReceipt
    {
        $can = $this->world['can']->id;
        $receipt = $this->receipt('12', [['packaging_id' => $can, 'count' => 2], ['quantity' => '2']]);
        $this->dispose($receipt, 'PASS', '2', [['kind' => 'LOOSE', 'quantity' => '2']]);
        $this->dispose($receipt, $rejectOutcome, '5', [['kind' => 'SEALED', 'packaging_id' => $can, 'count' => 1]]);

        return $receipt;
    }

    /**
     * @param  list<array<string,mixed>>  $portion
     * @return array<string,mixed>
     */
    private function exitInput(GoodsReceipt $receipt, string $kind, string $qty, array $portion = [], string $key = 'exit-1', array $extra = []): array
    {
        return array_merge(['kind' => $kind, 'goods_receipt_line_id' => $receipt->lines()->sole()->id, 'quantity' => $qty, 'request_key' => $key, 'reason' => 'Rejected on inspection', 'portion' => $portion], $extra);
    }

    /** @return array<string,mixed> */
    private function balance(): array
    {
        return collect(app(ReadPackageBalances::class)($this->world['item']->id, $this->world['a'], null, $this->world['actor']))->sole();
    }

    private function flush(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function refused(callable $call, ?string $code = null): DomainInvariantException
    {
        try {
            $call();
        } catch (DomainInvariantException $error) {
            if ($code !== null) {
                $this->assertSame($code, $error->reasonCode, $error->getMessage());
            }

            return $error;
        }
        $this->fail('The operation should have been refused.');
    }

    public function test_a_supplier_return_removes_the_rejected_can_at_the_layers_cost_and_leaves_usable_and_held_stock_alone(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $can = $this->world['can']->id;
        $before = $this->balance();
        $this->assertSame(['32.0000', '5.0000', '5.0000'], [$before['total'], $before['held']['total'], $before['rejected']['total']]);
        $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $receipt->lines()->sole()->id)->sole();

        $exit = app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '5', [['kind' => 'SEALED', 'packaging_id' => $can, 'count' => 1]]), $this->world['exiter']);

        $move = StockMove::query()->with('valuationEntries')->findOrFail($exit->stock_move_id);
        $this->assertSame(['SUPPLIER_RETURN', 'ISSUE', '5.0000'], [$move->source_type, $move->movement_kind, $move->qty]);
        $this->assertSame(0, bccomp((string) $move->valuationEntries->sum('base_amount'), bcmul('-5', (string) $layer->base_unit_cost, 4), 4), 'the exit leaves at the delivery layer\'s own cost');
        $this->assertSame('7.0000', $layer->refresh()->qty_remaining);
        $after = $this->balance();
        $this->assertSame(['27.0000', '5.0000', '0.0000', '22.0000'], [$after['total'], $after['held']['total'], $after['rejected']['total'], $after['usable_total']], 'usable and held are untouched');
        $this->assertSame([3, '7.0000'], [$after['sealed'][0]['count'], $after['loose']]);
        $this->assertSame([1], array_column($after['held']['sealed'], 'count'));
        $this->assertSame(['5.0000', '0.0000', null], [$exit->matching_qty, $exit->differing_qty, $exit->outcome_change_reason]);
        $evidence = PackageOperation::query()->where('rejected_stock_exit_id', $exit->id)->sole();
        $this->assertSame(['REJECTED_EXIT', $move->id], [$evidence->evidence_kind, $evidence->stock_move_id]);
        $this->flush();

        // The location is not countable until the held can is decided, but released stock is still issuable from this very layer (the exit did not eat it).
        app(IssueGroupedStock::class)(['item_id' => $this->world['item']->id, 'location_id' => $this->world['a'], 'request_key' => 'use-22', 'reason' => 'Job',
            'draw' => [['kind' => 'LOOSE', 'quantity' => '7'], ['kind' => 'SEALED', 'packaging_id' => $can, 'count' => 3]]], $this->world['actor']);
        $this->assertSame('5.0000', $this->balance()['total'], 'only the held can is left');
        $this->assertSame('5.0000', $layer->refresh()->qty_remaining);
        $this->flush();
    }

    public function test_a_scrap_of_the_rejected_can_posts_as_scrap_and_an_outcome_that_differs_from_qa_needs_its_reason(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery('RETURN_TO_SUPPLIER');
        $can = $this->world['can']->id;
        $portion = [['kind' => 'SEALED', 'packaging_id' => $can, 'count' => 1]];

        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SCRAP', '5', $portion), $this->world['exiter']), 'OUTCOME_CHANGE_REASON_REQUIRED');
        $this->assertSame(0, RejectedStockExit::query()->count());

        $exit = app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SCRAP', '5', $portion, extra: ['outcome_change_reason' => 'Supplier asked us to scrap locally']), $this->world['exiter']);
        $this->assertSame(['SCRAP', '0.0000', '5.0000', 'Supplier asked us to scrap locally'], [$exit->kind, $exit->matching_qty, $exit->differing_qty, $exit->outcome_change_reason]);
        $this->assertSame('SCRAP_WRITE_OFF', StockMove::query()->findOrFail($exit->stock_move_id)->source_type);
        $this->flush();
    }

    public function test_a_scrap_that_matches_the_qa_decision_needs_no_extra_reason(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery('SCRAP');
        $exit = app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SCRAP', '5', [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1]]), $this->world['exiter']);

        $this->assertSame(['5.0000', '0.0000', null], [$exit->matching_qty, $exit->differing_qty, $exit->outcome_change_reason]);
        $this->flush();
    }

    public function test_rejected_loose_litres_leave_as_loose_in_whole_or_in_part(): void
    {
        $this->grouped();
        $can = $this->world['can']->id;
        $receipt = $this->receipt('12', [['packaging_id' => $can, 'count' => 2], ['quantity' => '2']]);
        $this->dispose($receipt, 'PASS', '10', [['kind' => 'SEALED', 'packaging_id' => $can, 'count' => 2]]);
        $this->dispose($receipt, 'SCRAP', '2', [['kind' => 'LOOSE', 'quantity' => '2']]);
        $this->assertSame('2.0000', $this->balance()['rejected']['loose']);

        // Loose rejected litres may leave in parts; the sealed can is a different matter (see the wrong-declaration test).
        app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SCRAP', '1', [['kind' => 'LOOSE', 'quantity' => '1']], 'part'), $this->world['exiter']);
        $this->assertSame('1.0000', $this->balance()['rejected']['loose']);
        $exit = app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SCRAP', '1', [['kind' => 'LOOSE', 'quantity' => '1']], 'rest'), $this->world['exiter']);
        $this->assertSame('0.0000', $this->balance()['rejected']['loose']);
        $this->assertSame('1.0000', $exit->quantity);
        $this->flush();
    }

    public function test_the_exit_cannot_exceed_the_rejected_quantity_or_take_usable_held_or_pending_stock(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $can = $this->world['can']->id;
        $portion = [['kind' => 'SEALED', 'packaging_id' => $can, 'count' => 1]];

        // 5 L is rejected; the usable loose, the held can and the older stock must not be reachable.
        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '6', $portion, 'over'), $this->world['exiter']), 'NOTHING_REJECTED_TO_EXIT');
        app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '5', $portion, 'ok'), $this->world['exiter']);
        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '1', [['kind' => 'LOOSE', 'quantity' => '1']], 'again'), $this->world['exiter']), 'NOTHING_REJECTED_TO_EXIT');
        $this->assertSame(1, RejectedStockExit::query()->count());
        $this->flush();
    }

    public function test_a_wrong_package_declaration_is_refused_and_nothing_posts(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $can = $this->world['can']->id;
        $moves = StockMove::query()->count();
        $exiter = $this->world['exiter'];

        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '5', [], 'none'), $exiter), 'NEEDS_DECLARED_PORTION');
        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '5', [['kind' => 'SEALED', 'packaging_id' => $can, 'count' => 2]], 'two'), $exiter), 'DRAW_NOT_ON_HAND');
        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '5', [['kind' => 'LOOSE', 'quantity' => '5']], 'loose'), $exiter), 'DRAW_NOT_ON_HAND');
        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '3', [['kind' => 'LOOSE', 'quantity' => '3']], 'part'), $exiter));
        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '5', [['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can, 'count' => 1, 'quantity' => '2']], 'open'), $exiter));
        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '4', [['kind' => 'SEALED', 'packaging_id' => $can, 'count' => 1]], 'sum'), $exiter));
        $this->assertSame([$moves, 0], [StockMove::query()->count(), RejectedStockExit::query()->count()]);
        $this->assertSame('5.0000', $this->balance()['rejected']['total']);
    }

    public function test_a_retry_returns_the_original_and_a_changed_payload_is_refused(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $portion = [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1]];
        $input = $this->exitInput($receipt, 'SUPPLIER_RETURN', '5', $portion);

        $first = app(PostRejectedStockExit::class)($input, $this->world['exiter']);
        $moves = StockMove::query()->count();
        $again = app(PostRejectedStockExit::class)($input, $this->world['exiter']);

        $this->assertSame([$first->id, $moves, 1], [$again->id, StockMove::query()->count(), PackageOperation::query()->where('evidence_kind', 'REJECTED_EXIT')->count()]);
        $this->refused(fn () => app(PostRejectedStockExit::class)(array_replace($input, ['reason' => 'A different story']), $this->world['exiter']), null);
        $this->refused(fn () => app(PostRejectedStockExit::class)(array_replace($input, ['kind' => 'SCRAP', 'outcome_change_reason' => 'x']), $this->world['exiter']));
        $this->assertSame(1, RejectedStockExit::query()->count());
        $this->flush();
    }

    public function test_a_failure_while_writing_the_evidence_rolls_back_the_movement_and_the_ledger(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $receipt->lines()->sole()->id)->sole();
        $quantity = $layer->qty_remaining;
        $moves = StockMove::query()->count();
        $name = 'eloquent.creating: '.PackageOperation::class;
        PackageOperation::getEventDispatcher()->listen($name, fn () => throw new \RuntimeException('evidence store failed'));

        try {
            app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '5', [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1]]), $this->world['exiter']);
            $this->fail('The evidence failure must surface.');
        } catch (\RuntimeException $error) {
            $this->assertSame('evidence store failed', $error->getMessage());
        } finally {
            PackageOperation::getEventDispatcher()->forget($name);
        }

        $this->assertSame([$moves, 0, $quantity], [StockMove::query()->count(), RejectedStockExit::query()->count(), $layer->refresh()->qty_remaining]);
        $this->assertSame('5.0000', $this->balance()['rejected']['total']);
    }

    public function test_a_movement_that_pretends_to_be_an_exit_or_edits_the_ledger_is_stopped_by_the_database(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $exit = app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '5', [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1]]), $this->world['exiter']);
        $this->flush();

        // The ledger is append-only, even for raw SQL.
        foreach (['UPDATE rejected_stock_exits SET reason = ?', 'DELETE FROM rejected_stock_exits WHERE id > 0'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql, str_contains($sql, '?') ? ['changed'] : []));
                $this->fail('The ledger must be immutable.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('immutable', $error->getMessage());
            }
        }

        // An unsupported writer posting a scrap-classed movement, with or without package scope, leaves no ledger row and cannot commit.
        $item = $this->world['item'];
        PackageWriteScope::run($item->id, fn () => app(PostInventoryMovement::class)->issuePosted([
            'item_id' => $item->id, 'location_id' => $this->world['a'], 'qty' => '1', 'uom_id' => $this->world['unit']->id, 'moved_at' => now(),
            'source_type' => 'SCRAP_WRITE_OFF', 'source_id' => 987001,
        ]));
        try {
            $this->flush();
            $this->fail('A scrap-classed movement without its exit record must not commit.');
        } catch (QueryException $error) {
            $this->assertMatchesRegularExpression('/needs package evidence|rejected-stock exit/', $error->getMessage());
        }
        $this->assertNotNull($exit->id);
    }

    public function test_an_ordinary_issue_cannot_pose_as_a_return_without_its_exit_record(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo, qty: '10.0000');
        $a = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '4', '8', 99301);
        app(PostInventoryMovement::class)->issue(['item_id' => $item->id, 'location_id' => $a, 'qty' => '1', 'uom_id' => $unit->id, 'moved_at' => now(), 'source_type' => 'SUPPLIER_RETURN', 'source_id' => 987002]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('needs its rejected-stock exit record');
        $this->flush();
    }

    public function test_the_action_needs_the_dedicated_capability_and_the_warehouse_scope(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $portion = [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1]];

        // Neither QA authority nor stock authority is enough, and a scrap capability does not post a return.
        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '5', $portion), $this->world['actor']));
        $scrapOnly = PurchasingActor::make(['inventory.scrap.post', 'inventory.package-state.view']);
        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '5', $portion), $scrapOnly));
        $noView = PurchasingActor::make(['purchasing.supplier-return.post']);
        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '5', $portion), $noView));
        $this->assertSame(0, RejectedStockExit::query()->count());
    }

    public function test_a_person_limited_to_their_units_cannot_exit_stock_of_a_warehouse_they_do_not_own(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $role = Role::create(['team_id' => 1, 'name' => 'Limited returns', 'guard_name' => 'web']);
        foreach (['purchasing.supplier-return.post', 'inventory.package-state.view'] as $name) {
            Permission::findOrCreate($name, 'web');
            $role->givePermissionTo($name);
        }
        $limited = User::factory()->create(['company_id' => 1]);
        $limited->assignRole($role);
        AccessScopeLimit::create(['user_id' => $limited->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS']);

        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '5', [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1]]), $limited));
        $this->assertSame(0, RejectedStockExit::query()->count());
    }

    public function test_a_quantity_only_item_exits_the_same_way_without_package_evidence_and_keeps_released_stock_issuable(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo, qty: '40.0000', qaRequired: true);
        $a = $this->locationId();
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['purchasing.quality-disposition.post']);
        $this->world = ['item' => $item, 'unit' => $unit, 'a' => $a, 'order' => $order, 'actor' => $actor, 'exiter' => $this->exiter()];
        $receipt = $this->receipt('8');
        $this->dispose($receipt, 'PASS', '5');
        $this->dispose($receipt, 'SCRAP', '3');
        $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $receipt->lines()->sole()->id)->sole();

        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SCRAP', '3', [['kind' => 'LOOSE', 'quantity' => '3']], 'portion'), $this->world['exiter']));
        $this->refused(fn () => app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SCRAP', '4', [], 'over'), $this->world['exiter']), 'NOTHING_REJECTED_TO_EXIT');
        $exit = app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SCRAP', '3'), $this->world['exiter']);

        $this->assertSame(['SCRAP_WRITE_OFF', '5.0000'], [StockMove::query()->findOrFail($exit->stock_move_id)->source_type, $layer->refresh()->qty_remaining]);
        $this->assertSame(0, PackageOperation::query()->count());
        // The five released litres are still issuable (the exit was not charged to them).
        app(PostInventoryMovement::class)->issue(['item_id' => $item->id, 'location_id' => $a, 'qty' => '5', 'uom_id' => $unit->id, 'moved_at' => now(), 'source_type' => 'TEST_ISSUE', 'source_id' => 5501]);
        $this->assertSame('0.0000', $layer->refresh()->qty_remaining);
        $this->flush();
    }

    public function test_avco_leaves_at_the_pool_average_and_the_pool_follows(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Avco, qty: '40.0000', qaRequired: true);
        $a = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '10', '8', 99201);
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['purchasing.quality-disposition.post']);
        $this->world = ['item' => $item, 'unit' => $unit, 'a' => $a, 'order' => $order, 'actor' => $actor, 'exiter' => $this->exiter()];
        $receipt = $this->receipt('10');
        $this->dispose($receipt, 'PASS', '6');
        $this->dispose($receipt, 'RETURN_TO_SUPPLIER', '4');
        $pool = AvcoPool::query()->where('item_id', $item->id)->sole();
        $average = (string) $pool->base_avg_unit_cost;
        $quantityBefore = (string) $pool->qty_on_hand;

        $exit = app(PostRejectedStockExit::class)($this->exitInput($receipt, 'SUPPLIER_RETURN', '4'), $this->world['exiter']);

        $entry = StockMove::query()->with('valuationEntries')->findOrFail($exit->stock_move_id)->valuationEntries->sole();
        $this->assertSame(['SOURCE_OUT', bcmul('-4', $average, 4)], [$entry->pool_effect, (string) $entry->base_amount]);
        $this->assertSame(bcsub($quantityBefore, '4', 4), (string) $pool->refresh()->qty_on_hand);
        $this->flush();
    }

    public function test_a_specific_serial_item_exits_its_rejected_unit(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Specific, ItemTracking::Serial, qty: '2.0000', qaRequired: true);
        $a = $this->locationId();
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['purchasing.quality-disposition.post']);
        $this->world = ['item' => $item, 'unit' => $unit, 'a' => $a, 'order' => $order, 'actor' => $actor, 'exiter' => $this->exiter()];
        $line = $this->postReceipt($order->lines()->sole()->id, $item->id, $unit->id, $a, '1', '10', 'SN-1');
        $receipt = GoodsReceiptLine::query()->where('goods_receipt_id', $line)->sole();
        app(PostQualityDisposition::class)($receipt, ['disposition' => 'SCRAP', 'qty' => '1', 'reason' => 'Cracked'], $actor->id);

        $exit = app(PostRejectedStockExit::class)(['kind' => 'SCRAP', 'goods_receipt_line_id' => $receipt->id, 'quantity' => '1', 'request_key' => 'sn-1', 'reason' => 'Cracked housing'], $this->world['exiter']);

        $move = StockMove::query()->findOrFail($exit->stock_move_id);
        $this->assertSame(['SN-1', 'SCRAP_WRITE_OFF'], [$move->serial_no, $move->source_type]);
        $this->assertSame('0.0000', StockLayer::query()->where('serial_no', 'SN-1')->sole()->qty_remaining);
        $this->flush();
    }
}
