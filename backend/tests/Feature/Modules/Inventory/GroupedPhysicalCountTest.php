<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\ActivateGroupedPackageTracking;
use App\Modules\Inventory\Actions\AddPhysicalCountLine;
use App\Modules\Inventory\Actions\CreatePhysicalCount;
use App\Modules\Inventory\Actions\IssuePackagedStock;
use App\Modules\Inventory\Actions\PostPhysicalCount;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Actions\RecordPhysicalCountComposition;
use App\Modules\Inventory\Actions\RecordPhysicalCountLine;
use App\Modules\Inventory\Actions\RefreshPhysicalCountLine;
use App\Modules\Inventory\Actions\ReplayPackageCheckpoint;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageIssueAllocation;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\PhysicalCountLine;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ValuationMethod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchasingActor;

/**
 * ADR 0092: a physical count of an item with grouped package tracking. XYZ Paints: layers A 6 L at 8.00 (older) and B 6 L at 10.00 = 12 L, counted
 * and activated as 2 sealed 5 L cans + 2 L loose. A count never rewrites history: equal totals post no movement and replace the composition; a
 * variance posts the governed movement and its successor states the counted composition.
 */
class GroupedPhysicalCountTest extends RecordPackageCheckpointTest
{
    /** @var array<string, mixed> */
    private array $world = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The stock tables keep whole seconds and round, so two postings inside one second could look back-dated; hold the clock on a whole second.
        Carbon::setTestNow(Carbon::now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function xyz(bool $second = false): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo, qty: '40.0000');
        $a = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '6', '8', 99101);
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '6', '10', 99102);
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['inventory.physical_count.create', 'inventory.package-state.view', 'inventory.package-state.open', 'inventory.package-state.issue',
            'inventory.package-state.activate']);
        $can = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'can', 'name' => '5 L can', 'stock_quantity' => '5', 'active' => true]);
        $carton = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'carton', 'name' => 'Carton of 4 cans', 'stock_quantity' => '20', 'inner_packaging_id' => $can->id, 'inner_count' => 4, 'active' => true]);
        $checkpoints = [app(RecordPackageCheckpoint::class)([
            'item_id' => $item->id, 'location_id' => $a, 'request_key' => 'a-count', 'reason' => 'Opening count', 'expected_quantity' => '12',
            'expected_layers' => StockLayer::query()->where('location_id', $a)->orderBy('id')->pluck('qty_remaining', 'id')->all(),
            'composition' => [['packaging_id' => $can->id, 'count' => 2], ['quantity' => '2']],
        ], $actor)];
        $b = null;
        if ($second) {
            $b = Location::create(['warehouse_id' => Location::query()->findOrFail($a)->warehouse_id, 'code' => 'bin-b', 'name' => 'Bin B'])->id;
            $this->directReceipt($item->id, $unit->id, $b, $order->currency_id, '5', '9', 99103);
            $checkpoints[] = app(RecordPackageCheckpoint::class)([
                'item_id' => $item->id, 'location_id' => $b, 'request_key' => 'b-count', 'reason' => 'Opening count', 'expected_quantity' => '5',
                'expected_layers' => StockLayer::query()->where('location_id', $b)->pluck('qty_remaining', 'id')->all(), 'composition' => [['packaging_id' => $can->id, 'count' => 1]],
            ], $actor);
        }
        app(ActivateGroupedPackageTracking::class)(['item_id' => $item->id, 'request_key' => 'activate', 'reason' => 'Start', 'checkpoints' => array_map(fn ($op) => $op->id, $checkpoints)], $actor);
        $creator = PurchasingActor::make(['inventory.physical_count.create', 'inventory.physical_count.count', 'inventory.package-state.view']);
        $poster = PurchasingActor::make(['inventory.physical_count.post', 'inventory.package-state.recount', 'inventory.package-state.view']);
        $this->world = ['item' => $item, 'unit' => $unit, 'a' => $a, 'b' => $b, 'can' => $can, 'carton' => $carton, 'actor' => $actor, 'creator' => $creator,
            'poster' => $poster, 'currency' => $order->currency_id, 'checkpoints' => $checkpoints];
    }

    /** @return array{PhysicalCount, PhysicalCountLine} */
    private function lineAt(int $location, array $composition, ?User $creator = null): array
    {
        $creator ??= $this->world['creator'];
        $count = app(CreatePhysicalCount::class)(['warehouse_id' => Location::query()->findOrFail($location)->warehouse_id], $creator);
        $line = app(AddPhysicalCountLine::class)($count, ['item_id' => $this->world['item']->id, 'location_id' => $location], $creator);
        app(RecordPhysicalCountComposition::class)($line, $composition, $creator);

        return [$count, $line->refresh()];
    }

    /** @return array<string, array{count:int, quantity:string}> */
    private function composition(PackageOperation $operation, User $actor): array
    {
        return collect(app(ReplayPackageCheckpoint::class)($operation->id, $actor)['composition'])
            ->mapToGroups(fn (array $row) => [($row['packaging_code'] ?? 'loose') => $row])->map(fn ($rows) => [
                'count' => (int) $rows->sum('sealed_count'), 'quantity' => (string) $rows->reduce(fn ($sum, $row) => bcadd($sum, $row['quantity'], 4), '0'),
            ])->all();
    }

    private function evidenceFor(PhysicalCountLine $line): PackageOperation
    {
        return PackageOperation::query()->where('physical_count_line_id', $line->id)->sole();
    }

    /** @return array<int,string> */
    private function layerTotals(PackageOperation $operation): array
    {
        $totals = [];
        foreach (PackageContribution::query()->whereIn('state_id', PackageState::query()->where('operation_id', $operation->id)->pluck('id'))->get() as $part) {
            $totals[$part->stock_layer_id] = bcadd($totals[$part->stock_layer_id] ?? '0', $part->quantity, 4);
        }
        ksort($totals);

        return $totals;
    }

    private function flush(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function test_a_composition_only_recount_posts_no_movement_and_replaces_the_composition(): void
    {
        $this->xyz();
        $moves = StockMove::query()->count();
        $layers = StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all();
        [$count, $line] = $this->lineAt($this->world['a'], [['packaging_id' => $this->world['can']->id, 'count' => 1], ['quantity' => '7']]);

        app(PostPhysicalCount::class)($count, [], $this->world['poster']);

        $evidence = $this->evidenceFor($line);
        $this->assertSame('COUNTED', $evidence->evidence_kind);
        $this->assertNull($evidence->stock_move_id);
        $this->assertSame($moves, StockMove::query()->count(), 'quantity unchanged, so no stock movement');
        $this->assertEquals($layers, StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all());
        $this->assertSame(['can' => ['count' => 1, 'quantity' => '5.0000'], 'loose' => ['count' => 0, 'quantity' => '7.0000']], $this->composition($evidence, $this->world['poster']));
        $this->assertSame(collect($layers)->map(fn ($q) => bcadd((string) $q, '0', 4))->all(), $this->layerTotals($evidence));
        $this->assertTrue(app(ReplayPackageCheckpoint::class)($evidence->id, $this->world['poster'])['matches_current_stock']);
        $this->assertNull($line->refresh()->posted_stock_move_id);
        $this->assertSame(1, PackageOperation::query()->where('physical_count_line_id', $line->id)->count());
        $this->flush();
    }

    public function test_a_loss_posts_the_governed_issue_and_the_counted_composition_becomes_authoritative(): void
    {
        $this->xyz();
        [$count, $line] = $this->lineAt($this->world['a'], [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '1']]);

        app(PostPhysicalCount::class)($count, [], $this->world['poster']);

        $evidence = $this->evidenceFor($line);
        $move = StockMove::query()->findOrFail($line->refresh()->posted_stock_move_id);
        $this->assertSame('COUNT_VARIANCE', $evidence->evidence_kind);
        $this->assertSame($move->id, $evidence->stock_move_id);
        $this->assertSame('ISSUE', $move->movement_kind);
        $this->assertSame('1.0000', $move->qty);
        $this->assertSame('8.0000', bcadd((string) abs((float) $move->valuationEntries()->sum('base_amount')), '0', 4), 'allocator-led: 1 L from the older 8.00 layer');
        $this->assertSame(['can' => ['count' => 2, 'quantity' => '10.0000'], 'loose' => ['count' => 0, 'quantity' => '1.0000']], $this->composition($evidence, $this->world['poster']));
        $this->assertSame(StockLayer::query()->where('location_id', $this->world['a'])->orderBy('id')->get()->mapWithKeys(fn ($l) => [$l->id => bcadd((string) $l->qty_remaining, '0', 4)])->filter(fn ($q) => $q !== '0.0000')->all(), $this->layerTotals($evidence));
        $this->assertSame(1, PackageIssueAllocation::query()->where('operation_id', $evidence->id)->count());
        $this->assertTrue(app(ReplayPackageCheckpoint::class)($evidence->id, $this->world['poster'])['matches_current_stock']);
        $this->flush();
    }

    public function test_the_same_quantity_found_in_a_different_shape_gives_the_same_ledger_but_a_different_composition(): void
    {
        $this->xyz();
        [$count, $line] = $this->lineAt($this->world['a'], [['packaging_id' => $this->world['can']->id, 'count' => 1], ['quantity' => '6']]);

        app(PostPhysicalCount::class)($count, [], $this->world['poster']);

        $evidence = $this->evidenceFor($line);
        $this->assertSame('1.0000', StockMove::query()->findOrFail($line->refresh()->posted_stock_move_id)->qty);
        $this->assertSame(['can' => ['count' => 1, 'quantity' => '5.0000'], 'loose' => ['count' => 0, 'quantity' => '6.0000']], $this->composition($evidence, $this->world['poster']));
        $this->flush();
    }

    public function test_a_gain_needs_a_found_cost_and_posts_the_governed_receipt(): void
    {
        $this->xyz();
        [$count, $line] = $this->lineAt($this->world['a'], [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '3']]);
        $moves = StockMove::query()->count();
        try {
            app(PostPhysicalCount::class)($count, [], $this->world['poster']);
            $this->fail('A gain without a found cost must be refused.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('found-stock unit cost', $error->getMessage());
        }
        $this->assertSame($moves, StockMove::query()->count());
        $this->assertSame(0, PackageOperation::query()->whereNotNull('physical_count_line_id')->count());

        app(PostPhysicalCount::class)($count->refresh(), [$line->id => '9'], $this->world['poster']);

        $evidence = $this->evidenceFor($line);
        $move = StockMove::query()->findOrFail($line->refresh()->posted_stock_move_id);
        $this->assertSame(['RECEIPT', '1.0000'], [$move->movement_kind, $move->qty]);
        $this->assertSame(['can' => ['count' => 2, 'quantity' => '10.0000'], 'loose' => ['count' => 0, 'quantity' => '3.0000']], $this->composition($evidence, $this->world['poster']));
        $this->assertSame(3, StockLayer::query()->where('location_id', $this->world['a'])->count());
        $this->assertTrue(app(ReplayPackageCheckpoint::class)($evidence->id, $this->world['poster'])['matches_current_stock']);
        $this->flush();
    }

    public function test_finding_nothing_empties_the_scope_without_negative_stock(): void
    {
        $this->xyz();
        [$count, $line] = $this->lineAt($this->world['a'], []);

        app(PostPhysicalCount::class)($count, [], $this->world['poster']);

        $evidence = $this->evidenceFor($line);
        $this->assertSame('12.0000', StockMove::query()->findOrFail($line->refresh()->posted_stock_move_id)->qty);
        $this->assertSame(0, PackageState::query()->where('operation_id', $evidence->id)->count());
        $replay = app(ReplayPackageCheckpoint::class)($evidence->id, $this->world['poster']);
        $this->assertTrue($replay['integrity_valid']);
        $this->assertTrue($replay['matches_current_stock']);
        $this->assertSame(0, bccomp((string) StockLayer::query()->where('location_id', $this->world['a'])->sum('qty_remaining'), '0', 4), 'the scope is empty, never negative');
        $this->flush();
    }

    public function test_stock_that_moved_after_capture_refuses_the_whole_count_until_the_line_is_refreshed_and_recounted(): void
    {
        $this->xyz();
        [$count, $line] = $this->lineAt($this->world['a'], [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '1']]);
        app(IssuePackagedStock::class)(['predecessor_id' => $this->world['checkpoints'][0]->id, 'request_key' => 'job', 'reason' => 'Job', 'source_type' => 'TEST_JOB', 'source_id' => 1,
            'moved_at' => now(), 'draw' => [['kind' => 'LOOSE', 'quantity' => '1']]], $this->world['actor']);
        $moves = StockMove::query()->count();

        try {
            app(PostPhysicalCount::class)($count, [], $this->world['poster']);
            $this->fail('A stale count must be refused.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString("line {$line->id}", $error->getMessage());
            $this->assertStringContainsString('Recount before posting', $error->getMessage());
        }
        $this->assertSame($moves, StockMove::query()->count());
        $this->assertSame(0, PackageOperation::query()->whereNotNull('physical_count_line_id')->count());

        $fresh = app(RefreshPhysicalCountLine::class)($line, $this->world['creator']);
        $this->assertNull($fresh->counted_qty);
        $this->assertSame('11.0000', $fresh->book_qty);
        app(RecordPhysicalCountComposition::class)($fresh, [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '1']], $this->world['creator']);
        app(PostPhysicalCount::class)($count->refresh(), [], $this->world['poster']);
        $this->assertSame('COUNTED', $this->evidenceFor($line)->evidence_kind, '11 L on the book and 11 L counted: a composition-only correction');
        $this->flush();
    }

    public function test_a_count_may_only_use_the_items_tracked_levels(): void
    {
        $this->xyz();
        $creator = $this->world['creator'];
        $count = app(CreatePhysicalCount::class)(['warehouse_id' => Location::query()->findOrFail($this->world['a'])->warehouse_id], $creator);
        $line = app(AddPhysicalCountLine::class)($count, ['item_id' => $this->world['item']->id, 'location_id' => $this->world['a']], $creator);

        try {
            app(RecordPhysicalCountComposition::class)($line, [['packaging_id' => $this->world['carton']->id, 'count' => 1]], $creator);
            $this->fail('An untracked level must be refused.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('does not track that package level', $error->getMessage());
        }
        $this->assertSame(0, DB::table('physical_count_line_compositions')->count());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('does not track that package level');
        DB::transaction(fn () => PackageState::query()->create(['operation_id' => $this->world['checkpoints'][0]->id, 'item_packaging_id' => $this->world['carton']->id, 'form' => 'SEALED',
            'availability' => 'USABLE', 'packaging_code' => 'carton', 'packaging_name' => 'Carton of 4 cans', 'sealed_count' => 1, 'content_quantity' => '20', 'stock_quantity' => '20']));
    }

    public function test_a_mixed_count_is_all_or_nothing_across_two_locations(): void
    {
        $this->xyz(second: true);
        $creator = $this->world['creator'];
        $warehouse = Location::query()->findOrFail($this->world['a'])->warehouse_id;
        $count = app(CreatePhysicalCount::class)(['warehouse_id' => $warehouse], $creator);
        $lineA = app(AddPhysicalCountLine::class)($count, ['item_id' => $this->world['item']->id, 'location_id' => $this->world['a']], $creator);
        app(RecordPhysicalCountComposition::class)($lineA, [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '1']], $creator);
        $lineB = app(AddPhysicalCountLine::class)($count, ['item_id' => $this->world['item']->id, 'location_id' => $this->world['b']], $creator);
        app(RecordPhysicalCountComposition::class)($lineB, [['packaging_id' => $this->world['can']->id, 'count' => 1], ['quantity' => '2']], $creator); // a gain, no cost supplied
        $moves = StockMove::query()->count();

        try {
            app(PostPhysicalCount::class)($count, [], $this->world['poster']);
            $this->fail('The second line must fail the whole count.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('found-stock unit cost', $error->getMessage());
        }

        $this->assertSame($moves, StockMove::query()->count(), "line A's loss did not survive");
        $this->assertSame(0, PackageOperation::query()->whereNotNull('physical_count_line_id')->count());
        $this->assertSame('draft', strtolower($count->refresh()->status->value));
    }

    public function test_permissions_scalar_entry_and_uncaptured_lines_are_handled(): void
    {
        $this->xyz();
        $creator = $this->world['creator'];
        [$count, $line] = $this->lineAt($this->world['a'], [['packaging_id' => $this->world['can']->id, 'count' => 2], ['quantity' => '2']]);

        try {
            app(RecordPhysicalCountLine::class)($line, '12', $creator);
            $this->fail('A scalar quantity must be refused for a grouped line.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('record the counted composition', $error->getMessage());
        }
        $noRecount = PurchasingActor::make(['inventory.physical_count.post', 'inventory.package-state.view']);
        try {
            app(PostPhysicalCount::class)($count, [], $noRecount);
            $this->fail('Posting needs the recount capability.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('recount and view authority', $error->getMessage());
        }
        try {
            app(PostPhysicalCount::class)($count, [], $creator);
            $this->fail('The creator cannot post.');
        } catch (DomainInvariantException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, PackageOperation::query()->whereNotNull('physical_count_line_id')->count());
    }

    public function test_a_line_added_before_activation_must_be_re_added_so_its_composition_is_counted(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo, qty: '40.0000');
        $a = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '5', '8', 99201);
        $creator = PurchasingActor::make(['inventory.physical_count.create', 'inventory.physical_count.count', 'inventory.package-state.view']);
        $count = app(CreatePhysicalCount::class)(['warehouse_id' => Location::query()->findOrFail($a)->warehouse_id], $creator);
        $line = app(AddPhysicalCountLine::class)($count, ['item_id' => $item->id, 'location_id' => $a], $creator);
        app(RecordPhysicalCountLine::class)($line, '5', $creator);
        $this->assertFalse($line->refresh()->grouped, 'a quantity-only item behaves exactly as before');

        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['inventory.physical_count.create', 'inventory.package-state.view', 'inventory.package-state.activate']);
        $can = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'can', 'name' => '5 L can', 'stock_quantity' => '5', 'active' => true]);
        $checkpoint = app(RecordPackageCheckpoint::class)(['item_id' => $item->id, 'location_id' => $a, 'request_key' => 'c', 'reason' => 'count', 'expected_quantity' => '5',
            'expected_layers' => StockLayer::query()->pluck('qty_remaining', 'id')->all(), 'composition' => [['packaging_id' => $can->id, 'count' => 1]]], $actor);
        app(ActivateGroupedPackageTracking::class)(['item_id' => $item->id, 'request_key' => 'act', 'reason' => 'Start', 'checkpoints' => [$checkpoint->id]], $actor);

        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('re-add the line');
        app(PostPhysicalCount::class)($count, [], PurchasingActor::make(['inventory.physical_count.post', 'inventory.package-state.recount', 'inventory.package-state.view']));
    }
}
