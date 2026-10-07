<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\ActivateGroupedPackageTracking;
use App\Modules\Inventory\Actions\AddPhysicalCountLine;
use App\Modules\Inventory\Actions\CreatePhysicalCount;
use App\Modules\Inventory\Actions\IssuePackagedStock;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\PostPhysicalCount;
use App\Modules\Inventory\Actions\PreviewGroupedActivation;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Actions\RecordPhysicalCountLine;
use App\Modules\Inventory\Actions\ReplayPackageCheckpoint;
use App\Modules\Inventory\Actions\RequestNegativeStockOverride;
use App\Modules\Inventory\Actions\RequestStockRecalculation;
use App\Modules\Inventory\Actions\TransferPackagedStock;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\PackageActivation;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordReceiptComposition;
use App\Modules\Purchasing\Models\GoodsReceipt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PurchasingActor;

/**
 * ADR 0091: activation is one atomic, database-validated transition, and afterwards no stock-changing path can modify the item's quantity
 * without valid package evidence or an explicit refusal. XYZ Paints: layers A 6 L at 8.00, B 14 L at 10.00; counted as 3 sealed 5 L cans and 5 L loose.
 */
class GroupedPackageActivationTest extends RecordPackageCheckpointTest
{
    /** @var array<string, mixed> */
    private array $world = [];

    /** @return array<string, array{ValuationMethod}> */
    public static function methods(): array
    {
        return ['FIFO' => [ValuationMethod::Fifo], 'AVCO' => [ValuationMethod::Avco]];
    }

    /** @return array{User, ItemPackaging, PackageOperation} */
    private function xyz(ValuationMethod $method = ValuationMethod::Fifo, bool $qa = false): array
    {
        [$order, $item, $unit] = $this->issuedOrder($method, qty: '40.0000', qaRequired: $qa);
        $a = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '6', '8', 99101);
        $this->directReceipt($item->id, $unit->id, $a, $order->currency_id, '14', '10', 99102);
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, [
            'inventory.physical_count.create', 'inventory.physical_count.count', 'inventory.physical_count.post', 'inventory.package-state.view', 'inventory.package-state.open',
            'inventory.package-state.issue', 'inventory.package-state.transfer', 'inventory.package-state.activate', 'inventory.recalculation.request', 'inventory.negative-stock.request',
        ]);
        $can = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'can', 'name' => '5 L can', 'stock_quantity' => '5', 'active' => true]);
        $count = app(RecordPackageCheckpoint::class)([
            'item_id' => $item->id, 'location_id' => $a, 'request_key' => 'xyz-count', 'reason' => 'Opening count',
            'expected_quantity' => '20', 'expected_layers' => StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all(),
            'composition' => [['packaging_id' => $can->id, 'count' => 3], ['quantity' => '5']],
        ], $actor);
        $this->world = ['item' => $item, 'unit' => $unit, 'a' => $a, 'order' => $order, 'currency' => $order->currency_id];

        return [$actor, $can, $count];
    }

    /** @return array<string, mixed> */
    private function activation(PackageOperation $count, string $key = 'activate-xyz'): array
    {
        return ['item_id' => $this->world['item']->id, 'request_key' => $key, 'reason' => 'Start grouped tracking', 'checkpoints' => [$count->id]];
    }

    /** @return array{User, ItemPackaging, PackageOperation, PackageActivation} */
    private function activated(ValuationMethod $method = ValuationMethod::Fifo): array
    {
        [$actor, $can, $count] = $this->xyz($method);

        return [$actor, $can, $count, app(ActivateGroupedPackageTracking::class)($this->activation($count), $actor)];
    }

    /** Prove the commit-time checks now, then go back to deferring them so later steps behave as they would in production. */
    private function flush(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function itemMode(): string
    {
        return (string) DB::table('items')->where('id', $this->world['item']->id)->value('package_tracking_mode');
    }

    public function test_activation_is_one_atomic_transition_backed_by_the_count(): void
    {
        [$actor, , $count] = $this->xyz();
        $preview = app(PreviewGroupedActivation::class)($this->world['item']->id, [$count->id], $actor);
        $this->assertTrue($preview['ready']);
        $this->assertSame('20.0000', $preview['scopes'][0]['quantity']);

        $activation = app(ActivateGroupedPackageTracking::class)($this->activation($count), $actor);

        $this->assertSame('PACKAGE_BALANCE', $this->itemMode());
        $this->assertSame($activation->id, (int) DB::table('items')->where('id', $this->world['item']->id)->value('package_tracking_activation_id'));
        $this->assertSame('QUANTITY_ONLY', $activation->prior_mode);
        $this->assertSame([$count->id], DB::table('inventory_package_activation_scopes')->where('activation_id', $activation->id)->pluck('operation_id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue(app(ReplayPackageCheckpoint::class)($count->id, $actor)['tracking_active']);
        $this->flush();
    }

    public function test_before_the_count_is_committed_nothing_changes_and_scalar_posting_still_works(): void
    {
        [$actor, , $count] = $this->xyz();
        $notReady = app(PreviewGroupedActivation::class)($this->world['item']->id, [], $actor);
        $this->assertFalse($notReady['ready']);
        $this->assertContains('A fresh counted checkpoint is required.', $notReady['scopes'][0]['problems']);

        app(PostInventoryMovement::class)->issue($this->issueInput($this->world['item']->id, $this->world['unit']->id, $this->world['a'], '1', 99201));
        $this->assertSame('QUANTITY_ONLY', $this->itemMode());
        try {
            app(ActivateGroupedPackageTracking::class)($this->activation($count), $actor);
            $this->fail('A stale count must not activate.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('LATER_STOCK_MOVEMENT', $error->getMessage());
        }
        $this->assertSame('QUANTITY_ONLY', $this->itemMode());
        $this->assertSame(0, PackageActivation::query()->count());
    }

    public function test_a_missing_checkpoint_for_a_stocked_scope_writes_nothing(): void
    {
        [$actor, , $count] = $this->xyz();
        $input = $this->activation($count);
        $input['checkpoints'] = [PackageOperation::query()->max('id') + 100];

        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('cannot be activated');
        try {
            app(ActivateGroupedPackageTracking::class)($input, $actor);
        } finally {
            $this->assertSame(0, PackageActivation::query()->count());
            $this->assertSame('QUANTITY_ONLY', $this->itemMode());
        }
    }

    public function test_activation_is_idempotent_and_cannot_be_repeated_or_changed(): void
    {
        [$actor, , $count, $first] = $this->activated();

        $this->assertSame($first->id, app(ActivateGroupedPackageTracking::class)($this->activation($count), $actor)->id);
        try {
            app(ActivateGroupedPackageTracking::class)(array_replace($this->activation($count), ['reason' => 'something else']), $actor);
            $this->fail('A changed payload on the key must be refused.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('different activation', $error->getMessage());
        }
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('already has grouped package tracking');
        app(ActivateGroupedPackageTracking::class)($this->activation($count, 'second-try'), $actor);
    }

    public function test_activation_needs_its_own_authority(): void
    {
        [$actor, , $count] = $this->xyz();
        $actor->revokePermissionTo('inventory.package-state.activate');

        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('activation and view authority');
        app(ActivateGroupedPackageTracking::class)($this->activation($count), $actor);
    }

    public function test_serial_or_specific_items_and_open_shortages_cannot_be_activated(): void
    {
        [$actor, , $count] = $this->xyz();
        $item = $this->world['item'];
        DB::table('items')->where('id', $item->id)->update(['valuation_method' => 'SPECIFIC']);

        $preview = app(PreviewGroupedActivation::class)($item->id, [$count->id], $actor);

        $this->assertFalse($preview['ready']);
        $this->assertContains('Only FIFO and AVCO items can be tracked by package.', $preview['blockers']);
    }

    public function test_the_database_alone_refuses_to_set_the_mode_without_a_valid_activation(): void
    {
        [, , $count] = $this->xyz();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('only through activation');
        DB::table('items')->where('id', $this->world['item']->id)->update(['package_tracking_mode' => 'PACKAGE_BALANCE']);
    }

    public function test_the_database_alone_refuses_an_activation_whose_evidence_is_stale(): void
    {
        [$actor, , $count] = $this->xyz();
        app(PostInventoryMovement::class)->issue($this->issueInput($this->world['item']->id, $this->world['unit']->id, $this->world['a'], '1', 99301));
        $activationId = DB::table('inventory_package_activations')->insertGetId([
            'company_id' => 1, 'item_id' => $this->world['item']->id, 'request_key' => 'raw', 'payload_hash' => str_repeat('a', 64), 'actor_id' => $actor->id,
            'reason' => 'raw', 'prior_mode' => 'QUANTITY_ONLY', 'new_mode' => 'PACKAGE_BALANCE', 'after_stock_move_id' => 0, 'activated_at' => now(),
        ]);
        DB::table('inventory_package_activation_scopes')->insert(['company_id' => 1, 'activation_id' => $activationId, 'location_id' => $this->world['a'], 'batch_id' => null, 'operation_id' => $count->id, 'quantity' => '19']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('fresh reconciled package evidence');
        DB::table('items')->where('id', $this->world['item']->id)->update(['package_tracking_mode' => 'PACKAGE_BALANCE', 'package_tracking_activation_id' => $activationId]);
    }

    public function test_every_scalar_writer_is_refused_for_a_grouped_item_with_its_own_message(): void
    {
        [$actor] = $this->activated();
        $item = $this->world['item'];
        $b = $this->otherLocationId('lahore');
        Company::query()->findOrFail(1)->update(['authorised_forward_recalculation_enabled' => true, 'negative_stock_mode' => 'AUTHORISED_OVERRIDE',
            'negative_stock_authorisation_mode' => 'PRIOR_APPROVAL', 'negative_stock_material_consumption_enabled' => true]);
        $moves = StockMove::query()->count();
        $count = app(CreatePhysicalCount::class)(['warehouse_id' => Location::query()->findOrFail($this->world['a'])->warehouse_id], $actor);
        $line = app(AddPhysicalCountLine::class)($count, ['item_id' => $item->id, 'location_id' => $this->world['a']], $actor);
        app(RecordPhysicalCountLine::class)($line, '19', $actor);

        $cases = [
            'scalar issue' => [fn () => app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $this->world['unit']->id, $this->world['a'], '1', 99401)), 'scalar issue'],
            'scalar transfer' => [fn () => app(PostInventoryMovement::class)->transfer($this->transferInput($item->id, $this->world['unit']->id, $this->world['a'], $b, '1', 99402)), 'scalar transfer'],
            'scalar receipt' => [fn () => $this->directReceipt($item->id, $this->world['unit']->id, $this->world['a'], $this->world['currency'], '1', '9', 99403), 'scalar receipt'],
            'receipt reversal' => [fn () => app(PostInventoryMovement::class)->reverseReceipt('DIRECT_RECEIPT', 99101, 'X', 1, now()), 'reversing a receipt'],
            'physical count' => [fn () => app(PostPhysicalCount::class)($count, [], $actor), 'posting a physical count'],
            'recalculation' => [fn () => app(RequestStockRecalculation::class)(['item_id' => $item->id, 'location_id' => $this->world['a'], 'qty' => '1', 'moved_at' => now(),
                'source_type' => 'X', 'source_id' => 1], $actor), 'a stock recalculation'],
            'negative-stock override' => [fn () => app(RequestNegativeStockOverride::class)(['item_id' => $item->id, 'location_id' => $this->world['a'], 'movement_class' => 'MATERIAL_CONSUMPTION',
                'requested_qty' => '500', 'source_type' => 'X', 'source_id' => 2, 'reason_code' => 'PAPERWORK_DELAY', 'explanation' => 'x'], $actor), 'negative-stock override'],
        ];
        foreach ($cases as $name => [$call, $fragment]) {
            try {
                $call();
                $this->fail("{$name} must be refused");
            } catch (DomainInvariantException $error) {
                $this->assertStringContainsString('grouped package tracking', $error->getMessage(), $name);
                $this->assertStringContainsString($fragment, $error->getMessage(), $name);
            }
        }
        $this->assertSame($moves, StockMove::query()->count(), 'nothing posted');
    }

    public function test_even_a_writer_that_forgets_its_guard_is_stopped_by_the_database(): void
    {
        [, , , $activation] = $this->activated();
        $item = $this->world['item'];
        $layer = StockLayer::query()->orderBy('id')->firstOrFail();

        foreach ([
            'raw layer update' => fn () => DB::table('stock_layers')->where('id', $layer->id)->update(['qty_remaining' => '1']),
            'model layer update' => fn () => $layer->update(['qty_remaining' => '1']),
            'raw layer delete' => fn () => DB::table('stock_layers')->where('id', $layer->id)->delete(),
            'raw move insert' => fn () => DB::table('stock_moves')->insert(['company_id' => 1, 'item_id' => $item->id, 'from_location_id' => $this->world['a'], 'qty' => '1', 'uom_id' => $this->world['unit']->id,
                'moved_at' => now(), 'source_type' => 'RAW', 'source_id' => 1, 'unit_cost' => '1', 'currency_id' => $this->world['currency'], 'fx_rate' => '1', 'movement_kind' => 'ISSUE']),
        ] as $name => $write) {
            try {
                DB::transaction($write);
                $this->fail("{$name} must be refused");
            } catch (QueryException|DomainInvariantException $error) {
                $this->assertStringContainsString('package-aware operations', $error->getMessage(), $name);
            }
        }
        $this->assertNotNull($activation->id);
    }

    public function test_a_move_made_under_the_package_flag_still_cannot_commit_without_its_evidence(): void
    {
        [, , , $activation] = $this->activated();
        $item = $this->world['item'];
        $layerBefore = StockLayer::query()->orderBy('id')->firstOrFail()->qty_remaining;
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        DB::transaction(function () use ($item): void {
            DB::select("SELECT set_config('erp.package_aware_item', ?, true)", [(string) $item->id]);
            DB::table('stock_moves')->insert(['company_id' => 1, 'item_id' => $item->id, 'from_location_id' => $this->world['a'], 'qty' => '1', 'uom_id' => $this->world['unit']->id,
                'moved_at' => now(), 'source_type' => 'RAW', 'source_id' => 2, 'unit_cost' => '1', 'currency_id' => $this->world['currency'], 'fx_rate' => '1', 'movement_kind' => 'ISSUE']);
            $this->expectException(QueryException::class);
            $this->expectExceptionMessage('needs package evidence');
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $this->assertNotNull($activation);
        $this->assertSame($layerBefore, StockLayer::query()->orderBy('id')->firstOrFail()->qty_remaining);
    }

    #[DataProvider('methods')]
    public function test_package_aware_issue_and_transfer_are_the_ways_stock_still_moves(ValuationMethod $method): void
    {
        [$actor, $can, $count] = $this->activated($method);
        $b = $this->otherLocationId('lahore');

        $issued = app(IssuePackagedStock::class)(['predecessor_id' => $count->id, 'request_key' => 'use-1', 'reason' => 'Job', 'source_type' => 'TEST_JOB', 'source_id' => 1, 'moved_at' => now(),
            'draw' => [['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1]]], $actor);
        $moved = app(TransferPackagedStock::class)(['source_predecessor_id' => $issued['operation']->id, 'destination_predecessor_id' => null, 'to_location_id' => $b, 'request_key' => 'move-1',
            'reason' => 'Restock', 'source_type' => 'TEST_XFER', 'source_id' => 2, 'moved_at' => now(), 'draw' => [['kind' => 'LOOSE', 'quantity' => '2']]], $actor);

        $this->assertTrue(app(ReplayPackageCheckpoint::class)($moved['source']->id, $actor)['matches_current_stock']);
        $this->assertTrue(app(ReplayPackageCheckpoint::class)($moved['destination']->id, $actor)['matches_current_stock']);
        $this->flush();
    }

    public function test_a_grouped_item_receives_stock_only_with_its_observed_composition_and_the_chain_continues(): void
    {
        [$actor, $can, $count] = $this->activated();
        $receipt = $this->draftReceipt('12');
        app(RecordReceiptComposition::class)($receipt->lines()->sole(), [['packaging_id' => $can->id, 'count' => 2], ['quantity' => '2']], 'Two cans plus 2 L loose', $actor);

        app(PostGoodsReceipt::class)($receipt, $actor);

        $in = PackageOperation::query()->where('evidence_kind', 'RECEIPT_IN')->sole();
        $this->assertSame($count->id, $in->predecessor_id);
        $this->assertSame($receipt->lines()->sole()->id, $in->source_receipt_line_id);
        $snapshot = app(ReplayPackageCheckpoint::class)($in->id, $actor);
        $this->assertTrue($snapshot['matches_current_stock']);
        $this->assertSame('32.0000', $snapshot['recorded_quantity']);
        $this->assertSame('7.0000', $snapshot['open_loose_quantity']);
        $this->assertSame([5], array_values(array_filter(array_column($snapshot['composition'], 'sealed_count'))));
        $this->flush();

        $next = app(IssuePackagedStock::class)(['predecessor_id' => $in->id, 'request_key' => 'after-receipt', 'reason' => 'Job', 'source_type' => 'TEST_JOB', 'source_id' => 9, 'moved_at' => now(),
            'draw' => [['kind' => 'LOOSE', 'quantity' => '1']]], $actor);
        $this->assertSame('1.0000', $next['move']->qty);
    }

    public function test_a_grouped_item_cannot_receive_without_observed_composition_or_into_qa_hold_and_nothing_posts(): void
    {
        [$actor] = $this->activated();
        $moves = StockMove::query()->count();
        $receipt = $this->draftReceipt('4');

        try {
            app(PostGoodsReceipt::class)($receipt, $actor);
            $this->fail('An unobserved receipt must be refused.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('record the observed package composition', $error->getMessage());
        }
        $this->assertSame($moves, StockMove::query()->count());
        $this->assertSame(0, PackageOperation::query()->where('evidence_kind', 'RECEIPT_IN')->count());
    }

    public function test_a_qa_required_line_cannot_be_received_into_a_grouped_item(): void
    {
        [$actor, $can] = $this->xyz(qa: true);
        app(ActivateGroupedPackageTracking::class)($this->activation(PackageOperation::query()->where('request_key', 'xyz-count')->sole()), $actor);
        $receipt = $this->draftReceipt('5');
        app(RecordReceiptComposition::class)($receipt->lines()->sole(), [['packaging_id' => $can->id, 'count' => 1]], 'One sealed can', $actor);
        $moves = StockMove::query()->count();

        try {
            app(PostGoodsReceipt::class)($receipt, $actor);
            $this->fail('A QA-required line must be refused.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('QA-required line', $error->getMessage());
        }
        $this->assertSame($moves, StockMove::query()->count());
    }

    public function test_a_grouped_item_receives_into_an_empty_location_and_its_chain_starts_there(): void
    {
        [$actor, $can] = $this->activated();
        $b = $this->otherLocationId('lahore');
        $receipt = $this->draftReceipt('5', $b);
        app(RecordReceiptComposition::class)($receipt->lines()->sole(), [['packaging_id' => $can->id, 'count' => 1]], 'One sealed can', $actor);

        app(PostGoodsReceipt::class)($receipt, $actor);

        $in = PackageOperation::query()->where('evidence_kind', 'RECEIPT_IN')->sole();
        $this->assertNull($in->predecessor_id);
        $this->assertTrue(app(ReplayPackageCheckpoint::class)($in->id, $actor)['matches_current_stock']);
        $this->flush();
    }

    public function test_an_activated_item_cannot_change_how_its_stock_is_measured_or_valued(): void
    {
        $this->activated();
        $item = Item::query()->findOrFail($this->world['item']->id);
        $item->name = 'Renamed emulsion';
        $item->save();
        $this->assertSame('Renamed emulsion', $item->fresh()->name);

        $item->valuation_method = ValuationMethod::Avco;
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('cannot change how its stock is measured');
        $item->save();
    }

    public function test_quantity_only_items_are_completely_unchanged(): void
    {
        [, , $count] = $this->xyz();
        $item = $this->world['item'];

        $issued = app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $this->world['unit']->id, $this->world['a'], '2', 99501));
        $moved = app(PostInventoryMovement::class)->transfer($this->transferInput($item->id, $this->world['unit']->id, $this->world['a'], $this->otherLocationId('lahore'), '1', 99502));
        $this->directReceipt($item->id, $this->world['unit']->id, $this->world['a'], $this->world['currency'], '3', '9', 99503);

        $this->assertSame(['2.0000', '1.0000'], [$issued->qty, $moved->qty]);
        $this->assertSame('QUANTITY_ONLY', $this->itemMode());
        $this->assertNull(DB::table('items')->where('id', $item->id)->value('package_tracking_activation_id'));
        $this->assertFalse(app(ReplayPackageCheckpoint::class)($count->id, $this->purchasingActor())['tracking_active'] ?? false);
        $this->flush();
    }

    private function draftReceipt(string $qty, ?int $location = null): GoodsReceipt
    {
        return app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $this->world['order']->supplier_account_id,
            'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [[
                'purchase_order_line_id' => $this->world['order']->lines()->sole()->id, 'item_id' => $this->world['item']->id,
                'location_id' => $location ?? $this->world['a'], 'received_qty' => $qty, 'received_uom_id' => $this->world['unit']->id,
            ]],
        ], $this->purchasingActor());
    }
}
