<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\PostPackageOpening;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Actions\ReplayPackageCheckpoint;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchasingActor;

class PostPackageOpeningTest extends PreviewPackageOpeningTest
{
    public function test_unpack_then_open_persists_lineage_without_changing_stock_or_value(): void
    {
        [$input, $actor, $item] = $this->opening();
        $before = [StockMove::query()->get()->toArray(), StockLayer::query()->get()->toArray(),
            DB::table('avco_pools')->get()->map(fn ($row) => (array) $row)->all(), DB::table('stock_valuation_entries')->get()->map(fn ($row) => (array) $row)->all()];
        $unpack = app(PostPackageOpening::class)($input, $actor);
        $snapshot = app(ReplayPackageCheckpoint::class)($unpack->id, $actor);
        $this->assertTrue($snapshot['matches_current_stock']);
        $this->assertFalse($snapshot['tracking_active']);
        $this->assertSame($input['operation_id'], $snapshot['predecessor_id']);
        $this->assertSame([1, 20], array_column($snapshot['composition'], 'sealed_count'));
        $bottles = collect($snapshot['composition'])->firstWhere('packaging_code', 'bottle');
        $open = app(PostPackageOpening::class)(array_replace($input, ['operation_id' => $unpack->id,
            'state_id' => $bottles['state_id'], 'kind' => 'OPEN', 'request_key' => 'open-bottle']), $actor);
        $after = app(ReplayPackageCheckpoint::class)($open->id, $actor);
        $this->assertTrue($after['matches_current_stock']);
        $this->assertSame('0.1000', $after['open_loose_quantity']);
        $this->assertSame([1, 19, null], array_column($after['composition'], 'sealed_count'));
        $this->assertContains('SUPERSEDED_CHECKPOINT', app(ReplayPackageCheckpoint::class)($unpack->id, $actor)['mismatch_reasons']);
        $this->assertSame($before, [StockMove::query()->get()->toArray(), StockLayer::query()->get()->toArray(),
            DB::table('avco_pools')->get()->map(fn ($row) => (array) $row)->all(), DB::table('stock_valuation_entries')->get()->map(fn ($row) => (array) $row)->all()]);
        $this->assertSame('QUANTITY_ONLY', $item->fresh()->package_tracking_mode->value);
        // Exercise deferred conservation constraints even under RefreshDatabase's outer transaction.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_equal_retry_returns_original_after_later_stock_change(): void
    {
        [$input, $actor, $item, $unit] = $this->opening();
        $first = app(PostPackageOpening::class)($input, $actor);
        app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $unit->id, $first->location_id, '1', 99006));
        $this->assertSame($first->id, app(PostPackageOpening::class)($input, $actor)->id);
        $this->assertSame(2, PackageOperation::query()->count());
    }

    public function test_changed_request_payload_is_refused(): void
    {
        [$input, $actor] = $this->opening();
        app(PostPackageOpening::class)($input, $actor);
        $input['reason'] = 'Changed request';
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('different package evidence');
        app(PostPackageOpening::class)($input, $actor);
    }

    public function test_second_request_cannot_consume_the_same_predecessor(): void
    {
        [$input, $actor] = $this->opening();
        app(PostPackageOpening::class)($input, $actor);
        $input['request_key'] = 'another-operator';
        try {
            app(PostPackageOpening::class)($input, $actor);
            $this->fail('A stale predecessor must be refused.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('SUPERSEDED_CHECKPOINT', $error->getMessage());
        }
        $this->assertSame(2, PackageOperation::query()->count());
    }

    public function test_opening_permission_is_enforced_even_on_retry(): void
    {
        [$input, $actor] = $this->opening();
        app(PostPackageOpening::class)($input, $actor);
        $actor->revokePermissionTo('inventory.package-state.open');
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('opening authority');
        app(PostPackageOpening::class)($input, $actor);
    }

    public function test_archived_renamed_outer_identity_is_inherited_without_rewriting_history(): void
    {
        [$input, $actor] = $this->opening();
        $selected = PackageState::query()->findOrFail($input['state_id']);
        ItemPackaging::query()->findOrFail($selected->item_packaging_id)->update(['name' => 'Renamed carton', 'active' => false]);
        $operation = app(PostPackageOpening::class)($input, $actor);
        $remaining = PackageState::query()->where('operation_id', $operation->id)->where('item_packaging_id', $selected->item_packaging_id)->sole();
        $this->assertSame('Two litre carton', $remaining->packaging_name);
        $this->assertSame($selected->id, $remaining->origin_state_id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_saved_history_is_immutable_in_raw_sql(): void
    {
        [$input, $actor] = $this->opening();
        $operation = app(PostPackageOpening::class)($input, $actor);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('immutable');
        DB::transaction(fn () => DB::table('inventory_package_operations')->where('id', $operation->id)->update(['reason' => 'rewrite']));
    }

    public function test_request_lock_excludes_another_connection(): void
    {
        [$input, $actor] = $this->opening();
        app(PostPackageOpening::class)($input, $actor);
        config(['database.connections.opening_probe' => config('database.connections.testing')]);
        try {
            $result = DB::connection('opening_probe')->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired', ['package-checkpoint:1:'.$input['request_key']]);
            $this->assertFalse($result->acquired);
        } finally {
            DB::purge('opening_probe');
        }
    }

    public function test_database_rejects_a_successor_with_missing_contributions(): void
    {
        [$input, $actor] = $this->opening();
        $parent = PackageOperation::query()->findOrFail($input['operation_id']);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('complete composition');
        DB::transaction(function () use ($input, $actor, $parent): void {
            PackageOperation::query()->create(['item_id' => $parent->item_id, 'location_id' => $parent->location_id,
                'request_key' => 'raw-missing', 'payload_hash' => str_repeat('a', 64), 'evidence_kind' => 'OPEN',
                'actor_id' => $actor->id, 'reason' => 'raw invalid', 'recorded_at' => now(),
                'stock_move_watermark' => $parent->stock_move_watermark, 'predecessor_id' => $parent->id,
                'selected_state_id' => $input['state_id'], 'selected_count' => 1]);
            DB::statement('SET CONSTRAINTS package_operation_conservation IMMEDIATE');
        });
    }

    public function test_database_rejects_quantity_preserving_but_wrong_physical_transformation(): void
    {
        [$input, $actor] = $this->opening();
        $parent = PackageOperation::query()->findOrFail($input['operation_id']);
        $selected = PackageState::query()->findOrFail($input['state_id']);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('does not match selected transformation');
        DB::transaction(function () use ($input, $actor, $parent, $selected): void {
            $op = PackageOperation::query()->create(['item_id' => $parent->item_id, 'location_id' => $parent->location_id,
                'request_key' => 'raw-no-open', 'payload_hash' => str_repeat('b', 64), 'evidence_kind' => 'OPEN',
                'actor_id' => $actor->id, 'reason' => 'raw invalid', 'recorded_at' => now(),
                'stock_move_watermark' => $parent->stock_move_watermark, 'predecessor_id' => $parent->id,
                'selected_state_id' => $input['state_id'], 'selected_count' => 1]);
            $attributes = $selected->getAttributes();
            unset($attributes['id']);
            $state = PackageState::query()->create(array_replace($attributes, ['operation_id' => $op->id, 'origin_state_id' => $selected->id]));
            foreach (PackageContribution::query()->where('state_id', $selected->id)->get() as $part) {
                $attributes = $part->getAttributes();
                unset($attributes['id']);
                PackageContribution::query()->create(array_replace($attributes, ['state_id' => $state->id]));
            }
            DB::statement('SET CONSTRAINTS package_operation_conservation IMMEDIATE');
        });
    }

    public function test_opening_after_a_scalar_stock_change_rolls_back_without_partial_evidence(): void
    {
        [$input, $actor, $item, $unit] = $this->opening();
        $parent = PackageOperation::query()->findOrFail($input['operation_id']);
        app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $unit->id, $parent->location_id, '1', 99007));
        try {
            app(PostPackageOpening::class)($input, $actor);
            $this->fail('Stock changed.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('LATER_STOCK_MOVEMENT', $error->getMessage());
        }
        $this->assertSame(1, PackageOperation::query()->count());
        $this->assertSame(1, PackageState::query()->count());
    }

    public function test_read_permission_alone_cannot_post_an_opening(): void
    {
        [$operation, $state, $actor] = $this->sealedCheckpoint();
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('opening authority');
        app(PostPackageOpening::class)(['operation_id' => $operation->id, 'state_id' => $state->id,
            'count' => 1, 'kind' => 'OPEN', 'request_key' => 'reader-only', 'reason' => 'No posting authority'], $actor);
    }

    public function test_an_outer_transaction_failure_rolls_back_the_saved_transformation(): void
    {
        [$input, $actor] = $this->opening();
        try {
            DB::transaction(function () use ($input, $actor): void {
                app(PostPackageOpening::class)($input, $actor);
                throw new \RuntimeException('outer failure');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('outer failure', $error->getMessage());
        }
        $this->assertSame(1, PackageOperation::query()->count());
        $this->assertSame(1, PackageState::query()->count());
        $this->assertTrue(app(ReplayPackageCheckpoint::class)($input['operation_id'], $actor)['matches_current_stock']);
    }

    public function test_saved_opening_preserves_contributions_across_two_layers(): void
    {
        [$input, $actor, $item, $unit] = $this->opening();
        $parent = PackageOperation::query()->findOrFail($input['operation_id']);
        $this->directReceipt($item->id, $unit->id, $parent->location_id, StockMove::query()->firstOrFail()->currency_id, '6', '20', 99008);
        $pack = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'five', 'name' => 'Five litre can', 'stock_quantity' => '5', 'active' => true]);
        $checkpoint = app(RecordPackageCheckpoint::class)(['item_id' => $item->id, 'location_id' => $parent->location_id,
            'request_key' => 'two-layer-count', 'reason' => 'Observed two cans', 'expected_quantity' => '10',
            'expected_layers' => StockLayer::query()->pluck('qty_remaining', 'id')->all(),
            'composition' => [['packaging_id' => $pack->id, 'count' => 2]]], $actor);
        $input = array_replace($input, ['operation_id' => $checkpoint->id,
            'state_id' => PackageState::query()->where('operation_id', $checkpoint->id)->sole()->id,
            'kind' => 'OPEN', 'request_key' => 'open-layer-spanning-can']);
        $open = app(PostPackageOpening::class)($input, $actor);
        $snapshot = app(ReplayPackageCheckpoint::class)($open->id, $actor);
        $this->assertTrue($snapshot['matches_current_stock']);
        $loose = collect($snapshot['composition'])->firstWhere('form', 'OPEN_LOOSE');
        $this->assertSame(['4.0000', '1.0000'], array_column($loose['contributions'], 'quantity'));
        $this->assertSame('5.0000', $snapshot['composition'][0]['contributions'][0]['quantity']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    private function opening(): array
    {
        [$operation, $state, $actor, $item, $unit] = $this->sealedCheckpoint();
        PurchasingActor::grant($actor, ['inventory.package-state.open']);

        return [['operation_id' => $operation->id, 'state_id' => $state->id, 'count' => 1,
            'kind' => 'UNPACK', 'request_key' => 'unpack-carton', 'reason' => 'Unpacked one carton; inner bottles remain sealed.'], $actor, $item, $unit];
    }
}
