<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ValuationMethod;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchasingActor;

class RecordPackageCheckpointTest extends StockIssueTest
{
    public function test_authorised_checkpoint_records_exact_composition_without_stock_changes(): void
    {
        [$input, $actor] = $this->checkpoint();
        $moves = StockMove::query()->count();
        $operation = app(RecordPackageCheckpoint::class)($input, $actor);
        $this->assertSame('COUNTED', $operation->evidence_kind);
        $this->assertSame('4.0000', PackageState::query()->sole()->stock_quantity);
        $this->assertSame('4.0000', PackageContribution::query()->sole()->quantity);
        $this->assertSame($moves, StockMove::query()->count());
        $this->assertSame('4.0000', StockLayer::query()->sole()->qty_remaining);
    }

    public function test_retry_returns_original_checkpoint_even_after_stock_changes(): void
    {
        [$input, $actor, $item, $unit] = $this->checkpoint();
        $first = app(RecordPackageCheckpoint::class)($input, $actor);
        app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $unit->id, $input['location_id'], '1', 99002));
        $input['expected_quantity'] = '4.0000'; // Canonically equal decimal payload.
        $input['composition'][0]['quantity'] = '4.0';
        $retry = app(RecordPackageCheckpoint::class)($input, $actor);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, PackageOperation::query()->count());
    }

    public function test_changed_payload_on_same_key_is_refused(): void
    {
        [$input, $actor] = $this->checkpoint();
        app(RecordPackageCheckpoint::class)($input, $actor);
        $input['reason'] = 'different observation';
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('different package evidence');
        app(RecordPackageCheckpoint::class)($input, $actor);
    }

    public function test_direct_action_requires_count_creation_authority(): void
    {
        [$input] = $this->checkpoint();
        $outsider = User::factory()->create(['company_id' => 1]);
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('authority');
        app(RecordPackageCheckpoint::class)($input, $outsider);
    }

    public function test_stale_layer_snapshot_is_refused_even_when_composition_total_is_unchanged(): void
    {
        [$input, $actor] = $this->checkpoint();
        $input['expected_layers'][array_key_first($input['expected_layers'])] = '3';
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('Stock changed');
        app(RecordPackageCheckpoint::class)($input, $actor);
    }

    public function test_mismatched_composition_rolls_back_every_package_row(): void
    {
        [$input, $actor] = $this->checkpoint();
        $input['composition'][0]['quantity'] = '3';
        try {
            app(RecordPackageCheckpoint::class)($input, $actor);
            $this->fail('Expected reconciliation failure.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('does not reconcile', $error->getMessage());
        }
        $this->assertSame(0, PackageOperation::query()->count());
        $this->assertSame(0, PackageState::query()->count());
        $this->assertSame(0, PackageContribution::query()->count());
    }

    public function test_sealed_checkpoint_uses_definition_snapshot_and_can_span_cost_layers(): void
    {
        [$input, $actor, $item, $unit, $currency] = $this->checkpoint();
        $this->directReceipt($item->id, $unit->id, $input['location_id'], $currency, '6', '20', 99003);
        $input['expected_quantity'] = '10';
        $input['expected_layers'] = StockLayer::query()->orderBy('id')->pluck('qty_remaining', 'id')->all();
        $pack = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'five', 'name' => 'Five units', 'stock_quantity' => '5', 'active' => true]);
        $input['composition'] = [['packaging_id' => $pack->id, 'count' => 2]];
        app(RecordPackageCheckpoint::class)($input, $actor);
        $this->assertSame('SEALED', PackageState::query()->sole()->form);
        $this->assertSame('five', PackageState::query()->sole()->packaging_code);
        $this->assertSame(['4.0000', '6.0000'], PackageContribution::query()->orderBy('position')->pluck('quantity')->all());
    }

    public function test_finer_than_ledger_quantity_is_refused(): void
    {
        [$input, $actor] = $this->checkpoint();
        $input['composition'][0]['quantity'] = '4.00001';
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('exact decimals');
        app(RecordPackageCheckpoint::class)($input, $actor);
    }

    public function test_qa_receipt_requires_an_availability_adapter(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo, qaRequired: true);
        $location = $this->locationId();
        $this->postReceipt($order->lines()->sole()->id, $item->id, $unit->id, $location, '4', '10');
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['inventory.physical_count.create']);
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('physical availability adapter');
        app(RecordPackageCheckpoint::class)([
            'item_id' => $item->id, 'location_id' => $location, 'request_key' => 'qa',
            'reason' => 'observed', 'expected_quantity' => '4',
            'expected_layers' => StockLayer::query()->pluck('qty_remaining', 'id')->all(),
            'composition' => [['quantity' => '4']],
        ], $actor);
    }

    public function test_request_lock_excludes_a_second_database_connection(): void
    {
        [$input, $actor] = $this->checkpoint();
        app(RecordPackageCheckpoint::class)($input, $actor);
        config(['database.connections.package_probe' => config('database.connections.testing')]);
        try {
            $result = DB::connection('package_probe')->selectOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired',
                ['package-checkpoint:1:'.$input['request_key']],
            );
            $this->assertFalse($result->acquired);
        } finally {
            DB::purge('package_probe');
        }
    }

    public function test_retry_still_requires_current_authority(): void
    {
        [$input, $actor] = $this->checkpoint();
        app(RecordPackageCheckpoint::class)($input, $actor);
        $actor->revokePermissionTo('inventory.physical_count.create');
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('authority');
        app(RecordPackageCheckpoint::class)($input, $actor);
    }

    private function checkpoint(): array
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo);
        $location = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $location, $order->currency_id, '4', '10', 99001);
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['inventory.physical_count.create']);

        return [[
            'item_id' => $item->id, 'location_id' => $location,
            'request_key' => 'counted-package-test', 'reason' => 'Observed loose contents',
            'expected_quantity' => '4', 'expected_layers' => StockLayer::query()->pluck('qty_remaining', 'id')->all(),
            'composition' => [['quantity' => '4']],
        ], $actor, $item, $unit, $order->currency_id];
    }
}
