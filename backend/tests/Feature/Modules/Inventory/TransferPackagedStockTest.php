<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Inventory\Actions\IssuePackagedStock;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Actions\ReplayPackageCheckpoint;
use App\Modules\Inventory\Actions\TransferPackagedStock;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageIssueAllocation;
use App\Modules\Inventory\Models\PackageIssueDraw;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\PackageTransferComposition;
use App\Modules\Inventory\Support\ValuationMethod;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;

/**
 * ADR 0090 §9, grouped package-aware transfer. XYZ Paints at the Karachi depot: layer A 6 L at 8.00 (older), B 14 L at 10.00; counted as
 * 3 sealed 5 L cans (A 6 + B 9) and 5 L loose (B 5). A second location is the destination.
 */
class TransferPackagedStockTest extends RecordPackageCheckpointTest
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

    /** @return array<string, array{ValuationMethod}> */
    public static function methods(): array
    {
        return ['FIFO' => [ValuationMethod::Fifo], 'AVCO' => [ValuationMethod::Avco]];
    }

    /** @return array{User, ItemPackaging, PackageOperation} */
    private function karachi(ValuationMethod $method = ValuationMethod::Fifo, bool $withBox = false): array
    {
        [$order, $item, $unit] = $this->issuedOrder($method);
        $from = $this->locationId();
        $to = $this->otherLocationId('lahore');
        $this->directReceipt($item->id, $unit->id, $from, $order->currency_id, '6', '8', 99101);
        $this->directReceipt($item->id, $unit->id, $from, $order->currency_id, '14', '10', 99102);
        if ($withBox) {
            $this->directReceipt($item->id, $unit->id, $from, $order->currency_id, '40', '12', 99103);
        }
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['inventory.physical_count.create', 'inventory.package-state.view', 'inventory.package-state.open', 'inventory.package-state.issue', 'inventory.package-state.transfer']);
        $can = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'can', 'name' => '5 L can', 'stock_quantity' => '5', 'active' => true]);
        $box = $withBox ? ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'box', 'name' => 'Box of 4 cans', 'stock_quantity' => '20', 'inner_packaging_id' => $can->id, 'inner_count' => 4, 'active' => true]) : null;
        $count = app(RecordPackageCheckpoint::class)([
            'item_id' => $item->id, 'location_id' => $from, 'request_key' => 'khi-count', 'reason' => 'Opening count',
            'expected_quantity' => (string) ($withBox ? 60 : 20), 'expected_layers' => StockLayer::query()->where('location_id', $from)->orderBy('id')->pluck('qty_remaining', 'id')->all(),
            'composition' => $withBox ? [['packaging_id' => $box->id, 'count' => 3]] : [['packaging_id' => $can->id, 'count' => 3], ['quantity' => '5']],
        ], $actor);
        $this->world = ['item' => $item, 'unit' => $unit, 'from' => $from, 'to' => $to, 'can' => $can, 'box' => $box, 'currency' => $order->currency_id];

        return [$actor, $can, $count];
    }

    /** @param list<array<string,mixed>> $draw @return array<string,mixed> */
    private function request(PackageOperation $from, array $draw, string $key = 'xfer-1', int $source = 88001, ?PackageOperation $destination = null): array
    {
        return ['source_predecessor_id' => $from->id, 'destination_predecessor_id' => $destination?->id, 'to_location_id' => $this->world['to'],
            'request_key' => $key, 'reason' => 'Restock Lahore', 'source_type' => 'TEST_TRANSFER', 'source_id' => $source, 'moved_at' => now(), 'draw' => $draw];
    }

    /** @return array<string, array{count:int, quantity:string}> */
    private function composition(PackageOperation $operation, User $actor): array
    {
        return collect(app(ReplayPackageCheckpoint::class)($operation->id, $actor)['composition'])
            ->mapToGroups(fn (array $row) => [($row['packaging_code'] ?? 'loose') => $row])->map(fn ($rows) => [
                'count' => (int) $rows->sum('sealed_count'), 'quantity' => (string) $rows->reduce(fn ($sum, $row) => bcadd($sum, $row['quantity'], 4), '0'),
            ])->all();
    }

    private function cost(StockMove $move): string
    {
        return (string) $move->valuationEntries()->where('qty', '>', 0)->sum('base_amount');
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

    /** @return array<int,string> */
    private function layersAt(int $location): array
    {
        return StockLayer::query()->where('location_id', $location)->where('qty_remaining', '>', 0)->orderBy('id')->get()->mapWithKeys(fn ($layer) => [$layer->id => bcadd((string) $layer->qty_remaining, '0', 4)])->all();
    }

    #[DataProvider('methods')]
    public function test_whole_sealed_cans_move_sealed_and_both_sides_reconcile_to_their_own_layers(ValuationMethod $method): void
    {
        [$actor, $can, $count] = $this->karachi($method);

        $result = app(TransferPackagedStock::class)($this->request($count, [['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1]]), $actor);

        $this->assertSame('5.0000', $result['move']->qty);
        $this->assertSame(['TRANSFER_OUT', 'TRANSFER_IN'], [$result['source']->evidence_kind, $result['destination']->evidence_kind]);
        $this->assertSame($result['move']->id, $result['source']->stock_move_id);
        $this->assertSame($result['move']->id, $result['destination']->stock_move_id);
        $this->assertNull($result['destination']->predecessor_id, 'the destination held nothing, so its chain starts here');
        $this->assertSame(['can' => ['count' => 2, 'quantity' => '10.0000'], 'loose' => ['count' => 0, 'quantity' => '5.0000']], $this->composition($result['source'], $actor));
        $this->assertSame(['can' => ['count' => 1, 'quantity' => '5.0000']], $this->composition($result['destination'], $actor));
        $this->assertSame($this->layersAt($this->world['from']), $this->layerTotals($result['source']));
        $this->assertSame($this->layersAt($this->world['to']), $this->layerTotals($result['destination']));
        foreach (['source', 'destination'] as $leg) {
            $replay = app(ReplayPackageCheckpoint::class)($result[$leg]->id, $actor);
            $this->assertTrue($replay['matches_current_stock'], $leg);
            $this->assertFalse($replay['tracking_active']);
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_loose_quantity_moves_as_loose_and_merges_with_what_the_destination_already_holds(): void
    {
        [$actor, $can, $count] = $this->karachi();
        $to = $this->world['to'];
        $this->directReceipt($this->world['item']->id, $this->world['unit']->id, $to, $this->world['currency'], '11', '9', 99110);
        $destinationCount = app(RecordPackageCheckpoint::class)([
            'item_id' => $this->world['item']->id, 'location_id' => $to, 'request_key' => 'lhe-count', 'reason' => 'Lahore count',
            'expected_quantity' => '11', 'expected_layers' => StockLayer::query()->where('location_id', $to)->pluck('qty_remaining', 'id')->all(),
            'composition' => [['packaging_id' => $can->id, 'count' => 2], ['quantity' => '1']],
        ], $actor);

        $result = app(TransferPackagedStock::class)($this->request($count, [['kind' => 'LOOSE', 'quantity' => '3'], ['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1]], destination: $destinationCount), $actor);

        $this->assertSame('8.0000', $result['move']->qty);
        $this->assertSame($destinationCount->id, $result['destination']->predecessor_id);
        $this->assertSame(['can' => ['count' => 3, 'quantity' => '15.0000'], 'loose' => ['count' => 0, 'quantity' => '4.0000']], $this->composition($result['destination'], $actor));
        $this->assertSame($this->layersAt($to), $this->layerTotals($result['destination']));
        $this->assertSame(1, PackageState::query()->where('operation_id', $result['destination']->id)->where('form', 'OPEN_LOOSE')->count(), 'still one loose quantity per scope');
        $this->assertSame(['can' => ['count' => 2, 'quantity' => '10.0000'], 'loose' => ['count' => 0, 'quantity' => '2.0000']], $this->composition($result['source'], $actor));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_opening_a_can_at_the_source_and_moving_part_of_it_sends_loose_stock_and_keeps_the_rest_loose_at_the_source(): void
    {
        [$actor, $can, $count] = $this->karachi();

        $result = app(TransferPackagedStock::class)($this->request($count, [['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can->id, 'count' => 1, 'quantity' => '2']]), $actor);

        $this->assertSame('2.0000', $result['move']->qty);
        $this->assertSame(['can' => ['count' => 2, 'quantity' => '10.0000'], 'loose' => ['count' => 0, 'quantity' => '8.0000']], $this->composition($result['source'], $actor));
        $this->assertSame(['loose' => ['count' => 0, 'quantity' => '2.0000']], $this->composition($result['destination'], $actor), 'opened stock arrives open');
        $this->assertSame(['COUNTED', 'OPEN', 'TRANSFER_OUT', 'TRANSFER_IN'], PackageOperation::query()->orderBy('id')->pluck('evidence_kind')->all());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_a_tracked_box_moves_whole_or_is_unpacked_first_and_untracked_destinations_need_nothing_more(): void
    {
        [$actor, $can, $count] = $this->karachi(withBox: true);
        $box = $this->world['box'];

        $whole = app(TransferPackagedStock::class)($this->request($count, [['kind' => 'SEALED', 'packaging_id' => $box->id, 'count' => 1]], 'box-1', 88011), $actor);
        $this->assertSame(['box' => ['count' => 1, 'quantity' => '20.0000']], $this->composition($whole['destination'], $actor));
        $this->assertSame(['box' => ['count' => 2, 'quantity' => '40.0000']], $this->composition($whole['source'], $actor));

        $unpacked = app(TransferPackagedStock::class)($this->request($whole['source'], [
            ['kind' => 'UNPACK', 'packaging_id' => $box->id, 'count' => 1], ['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 2],
        ], 'box-2', 88012, $whole['destination']), $actor);
        $this->assertSame(['box' => ['count' => 1, 'quantity' => '20.0000'], 'can' => ['count' => 2, 'quantity' => '10.0000']], $this->composition($unpacked['source'], $actor));
        $this->assertSame(['box' => ['count' => 1, 'quantity' => '20.0000'], 'can' => ['count' => 2, 'quantity' => '10.0000']], $this->composition($unpacked['destination'], $actor));
        $this->assertSame($this->layersAt($this->world['to']), $this->layerTotals($unpacked['destination']));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /** @return array<string, array{list<array<string,mixed>>}> */
    public static function drawsOfFiveLitres(): array
    {
        return ['one sealed can' => [[['kind' => 'SEALED', 'packaging_id' => 'can', 'count' => 1]]], 'five loose litres' => [[['kind' => 'LOOSE', 'quantity' => '5']]]];
    }

    /** @param list<array<string,mixed>> $draw */
    #[DataProvider('drawsOfFiveLitres')]
    public function test_cost_follows_the_transfer_not_the_physical_choice(array $draw): void
    {
        [$actor, $can, $count] = $this->karachi();
        $draw = array_map(fn (array $line) => ($line['packaging_id'] ?? null) === 'can' ? array_replace($line, ['packaging_id' => $can->id]) : $line, $draw);

        $result = app(TransferPackagedStock::class)($this->request($count, $draw), $actor);

        $this->assertSame('40.0000', bcadd($this->cost($result['move']), '0', 4), '5 L from the older 8.00 layer, whichever form moved');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_equal_retry_returns_both_legs_and_the_original_move_and_moves_nothing_twice(): void
    {
        [$actor, $can, $count] = $this->karachi();
        $request = $this->request($count, [['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1]]);
        $first = app(TransferPackagedStock::class)($request, $actor);
        $moves = StockMove::query()->count();
        $layers = StockLayer::query()->count();

        $retry = app(TransferPackagedStock::class)($request, $actor);

        $this->assertSame([$first['source']->id, $first['destination']->id, $first['move']->id], [$retry['source']->id, $retry['destination']->id, $retry['move']->id]);
        $this->assertSame($moves, StockMove::query()->count());
        $this->assertSame($layers, StockLayer::query()->count());
        $request['reason'] = 'a different reason';
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('different package evidence');
        app(TransferPackagedStock::class)($request, $actor);
    }

    public function test_failure_while_writing_the_destination_leg_rolls_back_the_movement_and_both_legs(): void
    {
        [$actor, $can, $count] = $this->karachi();
        $moves = StockMove::query()->count();
        $layers = StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all();
        $operations = PackageOperation::query()->count();
        PackageIssueAllocation::creating(function (PackageIssueAllocation $allocation): void {
            if (PackageOperation::query()->findOrFail($allocation->operation_id)->evidence_kind === 'TRANSFER_IN') {
                throw new DomainInvariantException('simulated destination evidence failure');
            }
        });

        try {
            app(TransferPackagedStock::class)($this->request($count, [['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can->id, 'count' => 1, 'quantity' => '2']]), $actor);
            $this->fail('The failure must surface.');
        } catch (DomainInvariantException $error) {
            $this->assertSame('simulated destination evidence failure', $error->getMessage());
        } finally {
            PackageIssueAllocation::flushEventListeners();
        }

        $this->assertSame($moves, StockMove::query()->count(), 'the transfer did not post');
        $this->assertEquals($layers, StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all(), 'no layer moved or was created');
        $this->assertSame($operations, PackageOperation::query()->count(), 'neither leg nor the opening step survived');
        $this->assertSame(0, PackageIssueDraw::query()->count());
    }

    public function test_the_database_refuses_a_destination_leg_that_does_not_conserve(): void
    {
        [$actor, $can, $count] = $this->karachi();
        PackageIssueDraw::creating(function (PackageIssueDraw $draw): void {
            if (PackageOperation::query()->findOrFail($draw->operation_id)->evidence_kind === 'TRANSFER_IN') {
                $draw->quantity = '1.0000';
            }
        });
        try {
            app(TransferPackagedStock::class)($this->request($count, [['kind' => 'LOOSE', 'quantity' => '3']]), $actor);
            $this->expectException(QueryException::class);
            $this->expectExceptionMessage('equal the posted stock quantity');
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } finally {
            PackageIssueDraw::flushEventListeners();
        }
    }

    public function test_a_destination_holding_stock_without_evidence_is_refused_and_nothing_posts(): void
    {
        [$actor, $can, $count] = $this->karachi();
        $this->directReceipt($this->world['item']->id, $this->world['unit']->id, $this->world['to'], $this->world['currency'], '4', '9', 99120);
        $moves = StockMove::query()->count();

        try {
            app(TransferPackagedStock::class)($this->request($count, [['kind' => 'LOOSE', 'quantity' => '1']]), $actor);
            $this->fail('A destination without evidence must be refused.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('counted checkpoint there is required', $error->getMessage());
        }
        $this->assertSame($moves, StockMove::query()->count());
    }

    public function test_stale_source_evidence_is_refused(): void
    {
        [$actor, $can, $count] = $this->karachi();
        app(PostInventoryMovement::class)->issue(['item_id' => $this->world['item']->id, 'location_id' => $this->world['from'], 'qty' => '1', 'uom_id' => $this->world['unit']->id,
            'moved_at' => now(), 'source_type' => 'TEST_SCALAR', 'source_id' => 4]);

        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('fresh counted checkpoint is required');
        app(TransferPackagedStock::class)($this->request($count, [['kind' => 'LOOSE', 'quantity' => '1']]), $actor);
    }

    public function test_invalid_draws_and_locations_are_refused_and_post_nothing(): void
    {
        [$actor, $can, $count] = $this->karachi();
        $moves = StockMove::query()->count();
        $cases = [
            'more sealed than on hand' => [[['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 4]], 'more sealed packages'],
            'more loose than on hand' => [[['kind' => 'LOOSE', 'quantity' => '5.0001']], 'more open/loose'],
            'finer than the ledger' => [[['kind' => 'LOOSE', 'quantity' => '0.00005']], 'no finer than the stock ledger'],
            'a container' => [[['kind' => 'LOOSE', 'quantity' => '1', 'container_id' => 3]], 'does not identify individual containers'],
        ];
        foreach ($cases as $name => [$draw, $message]) {
            try {
                app(TransferPackagedStock::class)($this->request($count, $draw, 'bad-'.md5($name)), $actor);
                $this->fail("{$name} must be refused");
            } catch (DomainInvariantException $error) {
                $this->assertStringContainsString($message, $error->getMessage(), $name);
            }
        }
        $sameLocation = $this->request($count, [['kind' => 'LOOSE', 'quantity' => '1']], 'same') + [];
        $sameLocation['to_location_id'] = $this->world['from'];
        try {
            app(TransferPackagedStock::class)($sameLocation, $actor);
            $this->fail('Same-location transfer must be refused.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('different source and destination', $error->getMessage());
        }
        $this->assertSame($moves, StockMove::query()->count());
    }

    public function test_either_side_can_continue_its_own_chain_after_a_transfer(): void
    {
        [$actor, $can, $count] = $this->karachi();
        $out = app(TransferPackagedStock::class)($this->request($count, [['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1]]), $actor);

        $issued = app(IssuePackagedStock::class)(['predecessor_id' => $out['destination']->id, 'request_key' => 'lhe-use', 'reason' => 'Job at Lahore', 'source_type' => 'TEST_JOB',
            'source_id' => 5, 'moved_at' => now(), 'draw' => [['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can->id, 'count' => 1, 'quantity' => '2']]], $actor);
        $this->assertSame(['loose' => ['count' => 0, 'quantity' => '3.0000']], $this->composition($issued['operation'], $actor));

        $back = app(TransferPackagedStock::class)(array_replace($this->request($out['source'], [['kind' => 'LOOSE', 'quantity' => '1']], 'xfer-back', 88020, $issued['operation']),
            ['to_location_id' => $this->world['to']]), $actor);
        $this->assertSame('1.0000', $back['move']->qty);
        $this->assertTrue(app(ReplayPackageCheckpoint::class)($back['destination']->id, $actor)['matches_current_stock']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_quantity_only_stock_transfers_exactly_as_before_without_package_evidence(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo);
        $from = $this->locationId();
        $to = $this->otherLocationId('lahore');
        $this->directReceipt($item->id, $unit->id, $from, $order->currency_id, '4', '10', 99301);

        $move = app(PostInventoryMovement::class)->transfer(['item_id' => $item->id, 'from_location_id' => $from, 'to_location_id' => $to, 'qty' => '3',
            'uom_id' => $unit->id, 'moved_at' => now(), 'source_type' => 'TEST_TRANSFER', 'source_id' => 99302]);

        $this->assertSame('3.0000', $move->qty);
        $this->assertSame(0, PackageOperation::query()->count());
        $this->assertSame('QUANTITY_ONLY', $item->fresh()->package_tracking_mode->value);
    }

    public function test_transfer_authority_is_required_even_on_retry_and_both_ends_must_be_in_scope(): void
    {
        [$actor, $can, $count] = $this->karachi();
        $request = $this->request($count, [['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1]]);
        app(TransferPackagedStock::class)($request, $actor);
        $actor->revokePermissionTo('inventory.package-state.transfer');
        try {
            app(TransferPackagedStock::class)($request, $actor);
            $this->fail('Revoked authority must be refused on retry.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('transfer and view authority', $error->getMessage());
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $role = Role::create(['team_id' => 1, 'name' => 'Limited mover']);
        $role->givePermissionTo(Permission::findOrCreate('inventory.package-state.transfer'), Permission::findOrCreate('inventory.package-state.view'));
        $limited = User::factory()->create(['company_id' => 1]);
        $limited->assignRole($role);
        AccessScopeLimit::create(['user_id' => $limited->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS']);
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('not permitted to transfer package-tracked stock in this warehouse');
        app(TransferPackagedStock::class)($this->request(PackageOperation::query()->where('evidence_kind', 'TRANSFER_OUT')->firstOrFail(), [['kind' => 'LOOSE', 'quantity' => '1']], 'limited', 88030), $limited);
    }

    public function test_the_database_alone_refuses_to_start_a_destination_chain_where_stock_already_sits(): void
    {
        [$actor, $can, $count] = $this->karachi();
        $this->directReceipt($this->world['item']->id, $this->world['unit']->id, $this->world['to'], $this->world['currency'], '4', '9', 99130);
        $move = app(PostInventoryMovement::class)->transfer(['item_id' => $this->world['item']->id, 'from_location_id' => $this->world['from'], 'to_location_id' => $this->world['to'],
            'qty' => '1', 'uom_id' => $this->world['unit']->id, 'moved_at' => now(), 'source_type' => 'TEST_RAW', 'source_id' => 88040]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('needs fresh package evidence');
        DB::transaction(fn () => PackageOperation::query()->create(['item_id' => $move->item_id, 'location_id' => $this->world['to'], 'request_key' => 'raw-in',
            'payload_hash' => str_repeat('a', 64), 'evidence_kind' => 'TRANSFER_IN', 'actor_id' => $actor->id, 'reason' => 'raw', 'recorded_at' => now(),
            'stock_move_watermark' => $move->id, 'stock_move_id' => $move->id]));
    }

    public function test_destination_arithmetic_maps_each_declared_line_onto_the_layers_the_transfer_created(): void
    {
        $source = [['state_id' => 1, 'form' => 'SEALED', 'availability_evidence' => 'USABLE', 'packaging_id' => 9, 'packaging_code' => 'can', 'packaging_name' => '5 L can',
            'sealed_count' => 3, 'content_quantity' => '5.0000', 'quantity' => '15.0000', 'contributions' => []]];
        $destination = [['state_id' => 7, 'form' => 'OPEN_LOOSE', 'availability_evidence' => 'USABLE', 'packaging_id' => null, 'packaging_code' => null, 'packaging_name' => null,
            'sealed_count' => null, 'content_quantity' => null, 'quantity' => '1.0000', 'contributions' => [['layer_id' => 50, 'position' => 1, 'quantity' => '1.0000']]]];
        $draws = [['kind' => 'SEALED', 'packaging_id' => 9, 'count' => 1, 'quantity' => BigDecimal::of('5')], ['kind' => 'LOOSE', 'packaging_id' => null, 'count' => null, 'quantity' => BigDecimal::of('3')]];

        $rows = app(PackageTransferComposition::class)($destination, $source, $draws, [['layer_id' => 60, 'quantity' => '6.0000'], ['layer_id' => 61, 'quantity' => '2.0000']]);

        $this->assertCount(2, $rows);
        $loose = collect($rows)->firstWhere('form', 'OPEN_LOOSE');
        $sealed = collect($rows)->firstWhere('form', 'SEALED');
        $this->assertSame(7, $loose['origin_state_id']);
        $this->assertSame('4.0000', $loose['quantity']);
        $this->assertSame([['layer_id' => 50, 'position' => 1, 'quantity' => '1.0000'], ['layer_id' => 60, 'position' => 2, 'quantity' => '1.0000'], ['layer_id' => 61, 'position' => 3, 'quantity' => '2.0000']], $loose['contributions']);
        $this->assertSame([1, null], [$sealed['sealed_count'], $sealed['origin_state_id']]);
        $this->assertSame([['layer_id' => 60, 'position' => 1, 'quantity' => '5.0000']], $sealed['contributions']);
    }
}
