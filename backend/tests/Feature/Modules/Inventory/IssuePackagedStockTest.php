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
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageIssueAllocation;
use App\Modules\Inventory\Models\PackageIssueDraw;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\PackageIssueComposition;
use App\Modules\Inventory\Support\ValuationMethod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;

/**
 * ADR 0090, grouped package-aware issue. XYZ Paints: litres, a 5 L can, a 20 L box of four cans. Layer A holds 6 L at 8.00 (older) and
 * layer B 14 L at 10.00. Counted: 3 sealed cans (A 6 + B 9) and 5 L loose (B 5).
 */
class IssuePackagedStockTest extends RecordPackageCheckpointTest
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
    private function xyz(ValuationMethod $method, int $cans = 3, string $loose = '5', bool $withBox = false): array
    {
        [$order, $item, $unit] = $this->issuedOrder($method);
        $location = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $location, $order->currency_id, '6', '8', 99101);
        $this->directReceipt($item->id, $unit->id, $location, $order->currency_id, '14', '10', 99102);
        if ($withBox) {
            $this->directReceipt($item->id, $unit->id, $location, $order->currency_id, '40', '12', 99103);
        }
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['inventory.physical_count.create', 'inventory.package-state.view', 'inventory.package-state.open', 'inventory.package-state.issue']);
        $can = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'can', 'name' => '5 L can', 'stock_quantity' => '5', 'active' => true]);
        $box = $withBox ? ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'box', 'name' => 'Box of 4 cans', 'stock_quantity' => '20', 'inner_packaging_id' => $can->id, 'inner_count' => 4, 'active' => true]) : null;
        $composition = $withBox ? [['packaging_id' => $box->id, 'count' => 3]] : array_values(array_filter([
            $cans > 0 ? ['packaging_id' => $can->id, 'count' => $cans] : null, $loose !== '0' ? ['quantity' => $loose] : null,
        ]));
        $checkpoint = app(RecordPackageCheckpoint::class)([
            'item_id' => $item->id, 'location_id' => $location, 'request_key' => 'xyz-count', 'reason' => 'Opening count',
            'expected_quantity' => (string) ($withBox ? 60 : 20), 'expected_layers' => StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all(),
            'composition' => $composition,
        ], $actor);
        $this->world = ['item' => $item, 'unit' => $unit, 'location' => $location, 'can' => $can, 'box' => $box];

        return [$actor, $can, $checkpoint];
    }

    /** @param list<array<string,mixed>> $draw @return array<string,mixed> */
    private function request(PackageOperation $from, array $draw, string $key = 'issue-1', int $source = 77001): array
    {
        return ['predecessor_id' => $from->id, 'request_key' => $key, 'reason' => 'Job 1042 paint', 'source_type' => 'TEST_JOB_ISSUE',
            'source_id' => $source, 'moved_at' => now(), 'draw' => $draw];
    }

    /** @return array<string, mixed> */
    private function composition(PackageOperation $operation, User $actor): array
    {
        return collect(app(ReplayPackageCheckpoint::class)($operation->id, $actor)['composition'])
            ->mapToGroups(fn (array $row) => [($row['packaging_code'] ?? 'loose') => $row])->map(fn ($rows) => [
                'count' => $rows->sum('sealed_count'), 'quantity' => (string) $rows->reduce(fn ($sum, $row) => bcadd($sum, $row['quantity'], 4), '0'),
            ])->all();
    }

    private function cost(StockMove $move): string
    {
        return bcmul('-1', (string) $move->valuationEntries()->sum('base_amount'), 4);
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

    #[DataProvider('methods')]
    public function test_whole_sealed_can_posts_its_content_and_leaves_exact_package_state(ValuationMethod $method): void
    {
        [$actor, $can, $count] = $this->xyz($method);

        $result = app(IssuePackagedStock::class)($this->request($count, [['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1]]), $actor);

        $this->assertSame('5.0000', $result['move']->qty);
        $this->assertSame('ISSUE', $result['operation']->evidence_kind);
        $this->assertSame($result['move']->id, $result['operation']->stock_move_id);
        $this->assertSame('PACKAGE_BALANCE', $result['operation']->tracking_mode);
        $this->assertSame(['can' => ['count' => 2, 'quantity' => '10.0000'], 'loose' => ['count' => 0, 'quantity' => '5.0000']], $this->composition($result['operation'], $actor));
        $layers = StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->map(fn ($q) => (string) $q)->all();
        $this->assertSame(array_map(fn ($q) => bcadd($q, '0', 4), $layers), $this->layerTotals($result['operation']), 'evidence reconciles to the layers');
        $replay = app(ReplayPackageCheckpoint::class)($result['operation']->id, $actor);
        $this->assertTrue($replay['matches_current_stock']);
        $this->assertFalse($replay['tracking_active']);
        $this->assertContains('SUPERSEDED_CHECKPOINT', app(ReplayPackageCheckpoint::class)($count->id, $actor)['mismatch_reasons']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    #[DataProvider('methods')]
    public function test_cost_follows_the_allocator_not_the_physical_choice_and_contributions_are_relabelled(ValuationMethod $method): void
    {
        [$actor, , $count] = $this->xyz($method);
        [$older, $newer] = StockLayer::query()->orderBy('id')->pluck('id')->all();

        // The 5 L loose stock was attributed to the newer layer B; the allocator still takes the older layer A.
        $result = app(IssuePackagedStock::class)($this->request($count, [['kind' => 'LOOSE', 'quantity' => '5']]), $actor);

        $this->assertSame($method === ValuationMethod::Fifo ? '40.0000' : '47.0000', $this->cost($result['move']));
        $this->assertSame([$older => '1.0000', $newer => '14.0000'], $this->layerTotals($result['operation']));
        $this->assertSame(['can' => ['count' => 3, 'quantity' => '15.0000']], $this->composition($result['operation'], $actor));
        $this->assertSame([[$older, '5.0000']], PackageIssueAllocation::query()->get()->map(fn ($a) => [$a->stock_layer_id, $a->quantity])->all());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    #[DataProvider('methods')]
    public function test_draw_a_loose_whole_and_one_opened_can_leaves_one_can_and_three_litres_loose(ValuationMethod $method): void
    {
        [$actor, $can, $count] = $this->xyz($method);

        $result = app(IssuePackagedStock::class)($this->request($count, [
            ['kind' => 'LOOSE', 'quantity' => '5'], ['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1],
            ['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can->id, 'count' => 1, 'quantity' => '2'],
        ]), $actor);

        $this->assertSame('12.0000', $result['move']->qty);
        $this->assertSame(['can' => ['count' => 1, 'quantity' => '5.0000'], 'loose' => ['count' => 0, 'quantity' => '3.0000']], $this->composition($result['operation'], $actor));
        $this->assertSame(['COUNTED', 'OPEN', 'ISSUE'], PackageOperation::query()->orderBy('id')->pluck('evidence_kind')->all(), 'opened as its own step, then issued');
        $this->assertSame($method === ValuationMethod::Fifo ? '108.0000' : '112.8000', $this->cost($result['move']));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    #[DataProvider('methods')]
    public function test_the_other_valid_draw_of_twelve_litres_leaves_no_cans_and_eight_litres_loose_at_the_same_cost(ValuationMethod $method): void
    {
        [$actor, $can, $count] = $this->xyz($method);

        $result = app(IssuePackagedStock::class)($this->request($count, [
            ['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 2],
            ['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can->id, 'count' => 1, 'quantity' => '2'],
        ]), $actor);

        $this->assertSame('12.0000', $result['move']->qty);
        $this->assertSame(['loose' => ['count' => 0, 'quantity' => '8.0000']], $this->composition($result['operation'], $actor));
        $this->assertSame($method === ValuationMethod::Fifo ? '108.0000' : '112.8000', $this->cost($result['move']), 'packaging never changes cost');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_a_box_can_be_unpacked_inside_the_issue_when_boxes_are_tracked(): void
    {
        [$actor, $can, $count] = $this->xyz(ValuationMethod::Fifo, withBox: true);
        $box = $this->world['box'];

        $result = app(IssuePackagedStock::class)($this->request($count, [
            ['kind' => 'UNPACK', 'packaging_id' => $box->id, 'count' => 1],
            ['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 2],
            ['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can->id, 'count' => 1, 'quantity' => '2'],
        ]), $actor);

        $this->assertSame('12.0000', $result['move']->qty);
        $this->assertSame(['box' => ['count' => 2, 'quantity' => '40.0000'], 'can' => ['count' => 1, 'quantity' => '5.0000'], 'loose' => ['count' => 0, 'quantity' => '3.0000']], $this->composition($result['operation'], $actor));
        $this->assertSame(['COUNTED', 'UNPACK', 'OPEN', 'ISSUE'], PackageOperation::query()->orderBy('id')->pluck('evidence_kind')->all());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_drawing_everything_on_hand_leaves_an_empty_but_valid_successor(): void
    {
        [$actor, $can, $count] = $this->xyz(ValuationMethod::Fifo);
        $result = app(IssuePackagedStock::class)($this->request($count, [['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 3], ['kind' => 'LOOSE', 'quantity' => '5']]), $actor);

        $this->assertSame('20.0000', $result['move']->qty);
        $this->assertSame(0, PackageState::query()->where('operation_id', $result['operation']->id)->count());
        $replay = app(ReplayPackageCheckpoint::class)($result['operation']->id, $actor);
        $this->assertTrue($replay['integrity_valid']);
        $this->assertTrue($replay['matches_current_stock']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_equal_retry_returns_the_original_move_and_evidence_and_posts_nothing_twice(): void
    {
        [$actor, $can, $count] = $this->xyz(ValuationMethod::Fifo);
        $request = $this->request($count, [['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1]]);
        $first = app(IssuePackagedStock::class)($request, $actor);
        $moves = StockMove::query()->count();
        $remaining = StockLayer::query()->sum('qty_remaining');

        $retry = app(IssuePackagedStock::class)($request, $actor);

        $this->assertSame($first['operation']->id, $retry['operation']->id);
        $this->assertSame($first['move']->id, $retry['move']->id);
        $this->assertSame($moves, StockMove::query()->count());
        $this->assertEquals($remaining, StockLayer::query()->sum('qty_remaining'));
        $this->assertSame(1, PackageOperation::query()->where('evidence_kind', 'ISSUE')->count());
    }

    public function test_a_retry_still_returns_the_original_after_later_stock_progress_and_a_changed_payload_is_refused(): void
    {
        [$actor, $can, $count] = $this->xyz(ValuationMethod::Fifo);
        $request = $this->request($count, [['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1]]);
        $first = app(IssuePackagedStock::class)($request, $actor);
        app(PostInventoryMovement::class)->issue(['item_id' => $this->world['item']->id, 'location_id' => $this->world['location'], 'qty' => '1', 'uom_id' => $this->world['unit']->id,
            'moved_at' => now(), 'source_type' => 'TEST_SCALAR', 'source_id' => 1]);

        $this->assertSame($first['operation']->id, app(IssuePackagedStock::class)($request, $actor)['operation']->id);

        $request['reason'] = 'a different reason';
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('different package evidence');
        app(IssuePackagedStock::class)($request, $actor);
    }

    public function test_failure_while_writing_evidence_rolls_back_the_stock_issue_the_opening_and_every_row(): void
    {
        [$actor, $can, $count] = $this->xyz(ValuationMethod::Fifo);
        $moves = StockMove::query()->count();
        $remaining = (string) StockLayer::query()->sum('qty_remaining');
        $operations = PackageOperation::query()->count();
        PackageIssueAllocation::creating(fn () => throw new DomainInvariantException('simulated evidence failure'));

        try {
            app(IssuePackagedStock::class)($this->request($count, [['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can->id, 'count' => 1, 'quantity' => '2']]), $actor);
            $this->fail('The evidence failure must surface.');
        } catch (DomainInvariantException $error) {
            $this->assertSame('simulated evidence failure', $error->getMessage());
        } finally {
            PackageIssueAllocation::flushEventListeners();
        }

        $this->assertSame($moves, StockMove::query()->count(), 'the stock issue did not post');
        $this->assertSame($remaining, (string) StockLayer::query()->sum('qty_remaining'));
        $this->assertSame($operations, PackageOperation::query()->count(), 'the opening step did not survive either');
        $this->assertSame(0, PackageIssueDraw::query()->count());
    }

    public function test_the_database_refuses_evidence_that_does_not_conserve(): void
    {
        [$actor, $can, $count] = $this->xyz(ValuationMethod::Fifo);
        PackageIssueDraw::creating(function (PackageIssueDraw $draw): void {
            $draw->quantity = '1.0000'; // a draw that no longer equals the posted quantity
        });
        try {
            app(IssuePackagedStock::class)($this->request($count, [['kind' => 'LOOSE', 'quantity' => '3']]), $actor);
            $this->expectException(QueryException::class);
            $this->expectExceptionMessage('equal the posted stock quantity');
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } finally {
            PackageIssueDraw::flushEventListeners();
        }
    }

    public function test_grouped_evidence_cannot_name_a_container(): void
    {
        [$actor, $can, $count] = $this->xyz(ValuationMethod::Fifo);
        try {
            app(IssuePackagedStock::class)($this->request($count, [['kind' => 'LOOSE', 'quantity' => '1', 'container_id' => 7]]), $actor);
            $this->fail('A container line must be refused in grouped mode.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('does not identify individual containers', $error->getMessage());
        }
        $issued = app(IssuePackagedStock::class)($this->request($count, [['kind' => 'LOOSE', 'quantity' => '1']], 'real', 77030), $actor)['operation'];

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('package_issue_draw_shape');
        DB::table('inventory_package_issue_draws')->insert(['company_id' => 1, 'operation_id' => $issued->id, 'line_no' => 9, 'kind' => 'LOOSE', 'quantity' => '1', 'container_id' => 7]);
    }

    public function test_invalid_draws_are_refused_and_post_nothing(): void
    {
        [$actor, $can, $count] = $this->xyz(ValuationMethod::Fifo);
        $moves = StockMove::query()->count();
        $cases = [
            'more sealed than on hand' => [[['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 4]], 'more sealed packages'],
            'more loose than on hand' => [[['kind' => 'LOOSE', 'quantity' => '5.0001']], 'more open/loose'],
            'finer than the ledger' => [[['kind' => 'LOOSE', 'quantity' => '0.00005']], 'no finer than the stock ledger'],
            'opened remainder larger than the opened can' => [[['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can->id, 'count' => 1, 'quantity' => '5.5']], 'cannot exceed what they held'],
            'nothing taken' => [[], 'must take something'],
        ];
        foreach ($cases as $name => [$draw, $message]) {
            try {
                app(IssuePackagedStock::class)($this->request($count, $draw, 'bad-'.md5($name)), $actor);
                $this->fail("{$name} must be refused");
            } catch (DomainInvariantException $error) {
                $this->assertStringContainsString($message, $error->getMessage(), $name);
            }
        }
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('does not equal what the draw takes');
        app(IssuePackagedStock::class)($this->request($count, [['kind' => 'LOOSE', 'quantity' => '2']], 'stated') + ['quantity' => '3'], $actor);
        $this->assertSame($moves, StockMove::query()->count());
    }

    public function test_grouped_stock_becomes_authoritative_only_through_a_fresh_reconciled_count(): void
    {
        [$actor, $can, $count] = $this->xyz(ValuationMethod::Fifo);
        $item = $this->world['item'];

        // A scalar movement after the count makes the evidence stale: the grouped issue is refused and says to count again.
        app(PostInventoryMovement::class)->issue(['item_id' => $item->id, 'location_id' => $this->world['location'], 'qty' => '1', 'uom_id' => $this->world['unit']->id,
            'moved_at' => now(), 'source_type' => 'TEST_SCALAR', 'source_id' => 5]);
        try {
            app(IssuePackagedStock::class)($this->request($count, [['kind' => 'LOOSE', 'quantity' => '1']]), $actor);
            $this->fail('Stale evidence must be refused.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('fresh counted checkpoint is required', $error->getMessage());
            $this->assertStringContainsString('LATER_STOCK_MOVEMENT', $error->getMessage());
        }

        // Reconciling again (19 L now: 3 cans and 4 L loose) makes it authoritative from that checkpoint.
        $recount = app(RecordPackageCheckpoint::class)([
            'item_id' => $item->id, 'location_id' => $this->world['location'], 'request_key' => 'xyz-recount', 'reason' => 'Recount after scalar issue',
            'expected_quantity' => '19', 'expected_layers' => StockLayer::query()->where('qty_remaining', '>', 0)->orderBy('id')->pluck('qty_remaining', 'id')->all(),
            'composition' => [['packaging_id' => $can->id, 'count' => 3], ['quantity' => '4']],
        ], $actor);
        $result = app(IssuePackagedStock::class)($this->request($recount, [['kind' => 'LOOSE', 'quantity' => '1']], 'after-recount', 77010), $actor);

        $this->assertSame('1.0000', $result['move']->qty);
        $this->assertSame(['can' => ['count' => 3, 'quantity' => '15.0000'], 'loose' => ['count' => 0, 'quantity' => '3.0000']], $this->composition($result['operation'], $actor));
    }

    public function test_quantity_only_stock_keeps_posting_exactly_as_before_and_creates_no_package_evidence(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo);
        $location = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $location, $order->currency_id, '4', '10', 99301);

        $move = app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $unit->id, $location, '3', 99302));

        $this->assertSame('3.0000', $move->qty);
        $this->assertSame('1.0000', StockLayer::query()->sole()->qty_remaining);
        $this->assertSame(0, PackageOperation::query()->count());
        $this->assertSame('QUANTITY_ONLY', $item->fresh()->package_tracking_mode->value);
    }

    public function test_issue_authority_is_required_even_on_a_retry_and_opening_needs_its_own(): void
    {
        [$actor, $can, $count] = $this->xyz(ValuationMethod::Fifo);
        $request = $this->request($count, [['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1]]);
        app(IssuePackagedStock::class)($request, $actor);

        $actor->revokePermissionTo('inventory.package-state.issue');
        try {
            app(IssuePackagedStock::class)($request, $actor);
            $this->fail('Revoked authority must be refused on retry.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('issue and view authority', $error->getMessage());
        }

        $actor->givePermissionTo('inventory.package-state.issue');
        $actor->revokePermissionTo('inventory.package-state.open');
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('opening authority');
        app(IssuePackagedStock::class)($this->request($this->latest(), [['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can->id, 'count' => 1, 'quantity' => '1']], 'needs-open', 77020), $actor);
    }

    public function test_a_person_limited_to_their_units_cannot_issue_package_stock_from_a_warehouse_outside_them(): void
    {
        [$actor, $can, $count] = $this->xyz(ValuationMethod::Fifo);
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $role = Role::create(['team_id' => 1, 'name' => 'Limited packager']);
        $role->givePermissionTo(Permission::findOrCreate('inventory.package-state.issue'), Permission::findOrCreate('inventory.package-state.view'));
        $limited = User::factory()->create(['company_id' => 1]);
        $limited->assignRole($role);
        AccessScopeLimit::create(['user_id' => $limited->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS']);

        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('not permitted to issue package-tracked stock in this warehouse');
        app(IssuePackagedStock::class)($this->request($count, [['kind' => 'LOOSE', 'quantity' => '1']]), $limited);
    }

    public function test_composition_arithmetic_relabels_contributions_without_changing_any_state_total(): void
    {
        $row = fn (int $id, string $form, ?int $packaging, ?int $count, ?string $content, string $quantity, array $parts) => [
            'state_id' => $id, 'form' => $form, 'availability_evidence' => 'USABLE', 'packaging_id' => $packaging, 'packaging_code' => $packaging === null ? null : 'can',
            'packaging_name' => $packaging === null ? null : '5 L can', 'sealed_count' => $count, 'content_quantity' => $content, 'quantity' => $quantity,
            'contributions' => array_map(fn ($part, $i) => ['layer_id' => $part[0], 'position' => $i + 1, 'quantity' => $part[1]], $parts, array_keys($parts)),
        ];
        $composition = [$row(1, 'SEALED', 9, 3, '5.0000', '15.0000', [[1, '6.0000'], [2, '9.0000']]), $row(2, 'OPEN_LOOSE', null, null, null, '5.0000', [[2, '5.0000']])];

        $successor = app(PackageIssueComposition::class)($composition, [['kind' => 'LOOSE', 'packaging_id' => null, 'count' => null, 'quantity' => '5']], [1 => '5.0000']);

        $this->assertCount(1, $successor);
        $this->assertSame('15.0000', $successor[0]['quantity']);
        $this->assertSame([['layer_id' => 1, 'position' => 1, 'quantity' => '1.0000'], ['layer_id' => 2, 'position' => 2, 'quantity' => '14.0000']], $successor[0]['contributions']);
        $this->assertSame(1, $successor[0]['origin_state_id']);
    }

    private function latest(): PackageOperation
    {
        return PackageOperation::query()->orderByDesc('id')->firstOrFail();
    }
}
