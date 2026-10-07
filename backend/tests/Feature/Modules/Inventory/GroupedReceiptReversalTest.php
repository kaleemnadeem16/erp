<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\ActivateGroupedPackageTracking;
use App\Modules\Inventory\Actions\IssuePackagedStock;
use App\Modules\Inventory\Actions\PostPackageOpening;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Actions\ReplayPackageCheckpoint;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageIssueAllocation;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordReceiptComposition;
use App\Modules\Purchasing\Actions\ReverseGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchasingActor;

/**
 * ADR 0093: reversing a goods receipt of an item with grouped package tracking removes the physical draw that actually leaves now, never
 * restoring a past composition. XYZ Paints: 3 sealed 5 L cans + 5 L loose counted and activated; a receipt then brings 2 cans + 2 L loose (12 L).
 */
class GroupedReceiptReversalTest extends RecordPackageCheckpointTest
{
    /** @var array<string, mixed> */
    private array $world = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Stock tables keep whole seconds and round; hold the clock on a whole second so quick postings cannot look back-dated.
        Carbon::setTestNow(Carbon::now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Builds the world; with $activateFirst false the receipt is posted before activation (no observation exists for it). */
    private function xyz(bool $receiveAfterActivation = true): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo, qty: '60.0000');
        $a = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '6', '8', 99101);
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '14', '10', 99102);
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['inventory.physical_count.create', 'inventory.package-state.view', 'inventory.package-state.open', 'inventory.package-state.issue', 'inventory.package-state.activate']);
        $can = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'can', 'name' => '5 L can', 'stock_quantity' => '5', 'active' => true]);
        $this->world = ['item' => $item, 'unit' => $unit, 'a' => $a, 'can' => $can, 'actor' => $actor, 'order' => $order, 'currency' => $order->currency_id,
            'reverser' => PurchasingActor::make(['purchasing.goods-receipt.reverse', 'inventory.package-state.reverse', 'inventory.package-state.view'])];
        if ($receiveAfterActivation) {
            $count = $this->countAt('xyz-count', '20', [['packaging_id' => $can->id, 'count' => 3], ['quantity' => '5']]);
            $this->activate($count);
        }
    }

    /** @param list<array<string,mixed>> $composition */
    private function countAt(string $key, string $quantity, array $composition): PackageOperation
    {
        return app(RecordPackageCheckpoint::class)([
            'item_id' => $this->world['item']->id, 'location_id' => $this->world['a'], 'request_key' => $key, 'reason' => 'Count', 'expected_quantity' => $quantity,
            'expected_layers' => StockLayer::query()->where('location_id', $this->world['a'])->where('qty_remaining', '>', 0)->orderBy('id')->pluck('qty_remaining', 'id')->all(),
            'composition' => $composition,
        ], $this->world['actor']);
    }

    private function activate(PackageOperation $count): void
    {
        app(ActivateGroupedPackageTracking::class)(['item_id' => $this->world['item']->id, 'request_key' => 'activate', 'reason' => 'Start', 'checkpoints' => [$count->id]], $this->world['actor']);
    }

    /** @param list<array<string,mixed>>|null $composition */
    private function receipt(string $quantity, ?array $composition): GoodsReceipt
    {
        $receipt = app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $this->world['order']->supplier_account_id, 'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [['purchase_order_line_id' => $this->world['order']->lines()->sole()->id, 'item_id' => $this->world['item']->id,
                'location_id' => $this->world['a'], 'received_qty' => $quantity, 'received_uom_id' => $this->world['unit']->id]],
        ], $this->purchasingActor());
        if ($composition !== null) {
            app(RecordReceiptComposition::class)($receipt->lines()->sole(), $composition, 'Observed on arrival', $this->purchasingActor());
        }
        app(PostGoodsReceipt::class)($receipt, $this->world['actor']);

        return $receipt->refresh();
    }

    private function latest(): PackageOperation
    {
        return PackageOperation::query()->orderByDesc('id')->firstOrFail();
    }

    /** @return array<string, array{count:int, quantity:string}> */
    private function composition(PackageOperation $operation): array
    {
        return collect(app(ReplayPackageCheckpoint::class)($operation->id, $this->world['reverser'])['composition'])
            ->mapToGroups(fn (array $row) => [($row['packaging_code'] ?? 'loose') => $row])->map(fn ($rows) => [
                'count' => (int) $rows->sum('sealed_count'), 'quantity' => (string) $rows->reduce(fn ($sum, $row) => bcadd($sum, $row['quantity'], 4), '0'),
            ])->all();
    }

    private function flush(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function reverse(GoodsReceipt $receipt, array $draws = [], ?User $by = null): GoodsReceipt
    {
        return app(ReverseGoodsReceipt::class)($receipt, 'Wrong delivery', ($by ?? $this->world['reverser'])->id, $draws);
    }

    public function test_when_every_received_package_is_still_present_the_observed_composition_goes_back_by_default(): void
    {
        $this->xyz();
        $receipt = $this->receipt('12', [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '2']]);
        $received = $this->latest();
        $this->assertSame(['can' => ['count' => 5, 'quantity' => '25.0000'], 'loose' => ['count' => 0, 'quantity' => '7.0000']], $this->composition($received));

        $reversed = $this->reverse($receipt);

        $evidence = $this->latest();
        $move = StockMove::query()->findOrFail($evidence->stock_move_id);
        $this->assertSame(GoodsReceiptStatus::Reversed, $reversed->status);
        $this->assertSame('RECEIPT_REVERSAL', $evidence->evidence_kind);
        $this->assertSame($received->id, $evidence->reverses_operation_id);
        $this->assertSame(['REVERSAL', '12.0000'], [$move->movement_kind, $move->qty]);
        $this->assertSame(['can' => ['count' => 3, 'quantity' => '15.0000'], 'loose' => ['count' => 0, 'quantity' => '5.0000']], $this->composition($evidence), 'only what arrived went back');
        $this->assertSame(0, bccomp((string) StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->sum('qty_remaining'), '0', 4));
        $this->assertTrue(app(ReplayPackageCheckpoint::class)($evidence->id, $this->world['reverser'])['matches_current_stock']);
        $this->flush();
    }

    public function test_after_a_can_was_opened_a_declared_mixed_draw_goes_back_and_the_evidence_states_what_left(): void
    {
        $this->xyz();
        $receipt = $this->receipt('12', [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '2']]);
        $latest = $this->latest();
        $sealed = PackageState::query()->where('operation_id', $latest->id)->where('form', 'SEALED')->sole();
        app(PostPackageOpening::class)(['operation_id' => $latest->id, 'state_id' => $sealed->id, 'count' => 1, 'kind' => 'OPEN', 'request_key' => 'open-one', 'reason' => 'Opened'],
            PurchasingActor::make(['inventory.package-state.open', 'inventory.package-state.view']));
        $this->assertSame(['can' => ['count' => 4, 'quantity' => '20.0000'], 'loose' => ['count' => 0, 'quantity' => '12.0000']], $this->composition($this->latest()));

        $this->reverse($receipt, [$receipt->lines()->sole()->id => [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1], ['kind' => 'LOOSE', 'quantity' => '7']]]);

        $this->assertSame(['can' => ['count' => 3, 'quantity' => '15.0000'], 'loose' => ['count' => 0, 'quantity' => '5.0000']], $this->composition($this->latest()));
        $this->assertSame(1, PackageIssueAllocation::query()->where('operation_id', $this->latest()->id)->count(), 'the single reversed layer');
        $this->flush();
    }

    public function test_stock_drawn_from_the_receipts_own_layer_refuses_the_reversal_and_changes_nothing(): void
    {
        $this->xyz();
        $receipt = $this->receipt('12', [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '2']]);
        app(IssuePackagedStock::class)(['predecessor_id' => $this->latest()->id, 'request_key' => 'big-job', 'reason' => 'Job', 'source_type' => 'TEST_JOB', 'source_id' => 1, 'moved_at' => now(),
            'draw' => [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 3], ['kind' => 'LOOSE', 'quantity' => '6']]], $this->world['actor']);
        $moves = StockMove::query()->count();
        $operations = PackageOperation::query()->count();

        try {
            $this->reverse($receipt);
            $this->fail('A consumed receipt layer must refuse the reversal.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('partly consumed', $error->getMessage());
        }
        $this->assertSame([$moves, $operations], [StockMove::query()->count(), PackageOperation::query()->count()]);
        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->refresh()->status);
    }

    public function test_a_declared_draw_that_is_not_on_hand_is_refused_even_when_the_layer_is_untouched(): void
    {
        $this->xyz();
        $receipt = $this->receipt('12', [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '2']]);
        // All 7 L of loose stock is used; the allocator charges the older layers, so the received layer stays untouched.
        app(IssuePackagedStock::class)(['predecessor_id' => $this->latest()->id, 'request_key' => 'loose-job', 'reason' => 'Job', 'source_type' => 'TEST_JOB', 'source_id' => 2, 'moved_at' => now(),
            'draw' => [['kind' => 'LOOSE', 'quantity' => '7']]], $this->world['actor']);
        $moves = StockMove::query()->count();

        try {
            $this->reverse($receipt);
            $this->fail('A draw that is not on hand must be refused.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('more open/loose', $error->getMessage());
        }
        $this->assertSame($moves, StockMove::query()->count());
        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->refresh()->status);
    }

    public function test_the_declared_draw_must_add_up_to_the_whole_received_quantity(): void
    {
        $this->xyz();
        $receipt = $this->receipt('12', [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '2']]);

        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('add up to the whole received quantity');
        $this->reverse($receipt, [$receipt->lines()->sole()->id => [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 2]]]);
    }

    public function test_a_receipt_that_predates_activation_needs_an_explicit_draw(): void
    {
        $this->xyz(receiveAfterActivation: false);
        $receipt = $this->receipt('12', null);
        $count = $this->countAt('later-count', '32', [['packaging_id' => $this->world['can']->id, 'count' => 5], ['quantity' => '7']]);
        $this->activate($count);

        try {
            $this->reverse($receipt);
            $this->fail('Without observed evidence a draw must be declared.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('predates grouped tracking', $error->getMessage());
        }
        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->refresh()->status);

        $this->reverse($receipt, [$receipt->lines()->sole()->id => [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 2], ['kind' => 'LOOSE', 'quantity' => '2']]]);

        $evidence = $this->latest();
        $this->assertSame('RECEIPT_REVERSAL', $evidence->evidence_kind);
        $this->assertNull($evidence->reverses_operation_id, 'no receipt evidence existed to name');
        $this->assertSame(['can' => ['count' => 3, 'quantity' => '15.0000'], 'loose' => ['count' => 0, 'quantity' => '5.0000']], $this->composition($evidence));
        $this->flush();
    }

    public function test_reversing_twice_is_idempotent_and_posts_nothing_again(): void
    {
        $this->xyz();
        $receipt = $this->receipt('12', [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '2']]);
        $this->reverse($receipt);
        $moves = StockMove::query()->count();

        $again = $this->reverse($receipt->refresh());

        $this->assertSame(GoodsReceiptStatus::Reversed, $again->status);
        $this->assertSame($moves, StockMove::query()->count());
        $this->assertSame(1, PackageOperation::query()->where('evidence_kind', 'RECEIPT_REVERSAL')->count());
    }

    public function test_a_failure_while_writing_the_evidence_rolls_back_the_whole_reversal(): void
    {
        $this->xyz();
        $receipt = $this->receipt('12', [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '2']]);
        $moves = StockMove::query()->count();
        $layers = StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all();
        $operations = PackageOperation::query()->count();
        PackageIssueAllocation::creating(function (PackageIssueAllocation $allocation): void {
            if (PackageOperation::query()->findOrFail($allocation->operation_id)->evidence_kind === 'RECEIPT_REVERSAL') {
                throw new DomainInvariantException('simulated evidence failure');
            }
        });
        try {
            $this->reverse($receipt);
            $this->fail('The failure must surface.');
        } catch (DomainInvariantException $error) {
            $this->assertSame('simulated evidence failure', $error->getMessage());
        } finally {
            PackageIssueAllocation::flushEventListeners();
        }

        $this->assertSame($moves, StockMove::query()->count());
        $this->assertEquals($layers, StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all());
        $this->assertSame($operations, PackageOperation::query()->count());
        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->refresh()->status);
    }

    public function test_the_package_reverse_capability_and_scope_are_required(): void
    {
        $this->xyz();
        $receipt = $this->receipt('12', [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '2']]);
        $without = PurchasingActor::make(['purchasing.goods-receipt.reverse', 'inventory.package-state.view']);

        try {
            $this->reverse($receipt, [], $without);
            $this->fail('The package reverse capability is required.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('reverse and view authority', $error->getMessage());
        }
        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->refresh()->status);
    }

    public function test_the_database_requires_evidence_for_a_reversal_move_and_the_right_link(): void
    {
        $this->xyz();
        $first = $this->receipt('12', [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '2']]);
        $this->receipt('5', [['packaging_id' => $this->world['can']->id, 'count' => 1]]);
        $other = PackageOperation::query()->where('evidence_kind', 'RECEIPT_IN')->orderByDesc('id')->firstOrFail();
        PackageOperation::creating(function (PackageOperation $operation) use ($other): void {
            if ($operation->evidence_kind === 'RECEIPT_REVERSAL') {
                $operation->reverses_operation_id = $other->id; // names the wrong receipt's evidence
            }
        });
        try {
            $this->reverse($first);
            $this->fail('A reversal naming the wrong receipt evidence must be refused.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('must name the receipt evidence it undoes', $error->getMessage());
        } finally {
            PackageOperation::flushEventListeners();
        }
    }

    public function test_quantity_only_receipt_reversal_is_unchanged(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo, qty: '10.0000');
        $location = $this->locationId();
        $actor = $this->purchasingActor();
        $receipt = app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $order->supplier_account_id, 'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [['purchase_order_line_id' => $order->lines()->sole()->id, 'item_id' => $item->id, 'location_id' => $location, 'received_qty' => '4', 'received_uom_id' => $unit->id]],
        ], $actor);
        app(PostGoodsReceipt::class)($receipt, $actor);

        app(ReverseGoodsReceipt::class)($receipt, 'Wrong delivery', PurchasingActor::make(['purchasing.goods-receipt.reverse'])->id);

        $this->assertSame(GoodsReceiptStatus::Reversed, $receipt->refresh()->status);
        $this->assertSame(0, PackageOperation::query()->count());
        $this->assertSame('REVERSAL', StockMove::query()->orderByDesc('id')->firstOrFail()->movement_kind);
    }
}
