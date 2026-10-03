<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Actions\ReplayPackageCheckpoint;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchasingActor;

class ReplayPackageCheckpointTest extends RecordPackageCheckpointTest
{
    public function test_replay_reconstructs_exact_evidence_without_enabling_tracking_or_cost_access(): void
    {
        [$input, $actor] = $this->checkpoint();
        PurchasingActor::grant($actor, ['inventory.package-state.view']);
        $operation = app(RecordPackageCheckpoint::class)($input, $actor);
        $result = app(ReplayPackageCheckpoint::class)($operation->id, $actor);
        $this->assertTrue($result['integrity_valid']);
        $this->assertTrue($result['matches_current_stock']);
        $this->assertFalse($result['tracking_active']);
        $this->assertSame('4.0000', $result['recorded_quantity']);
        $this->assertSame('4.0000', $result['open_loose_quantity']);
        $this->assertArrayNotHasKey('unit_cost', $result['composition'][0]['contributions'][0]);
        $this->assertSame($result, app(ReplayPackageCheckpoint::class)($operation->id, $actor));
    }

    public function test_later_issue_preserves_history_and_reports_stale_evidence(): void
    {
        [$input, $actor, $item, $unit] = $this->checkpoint();
        PurchasingActor::grant($actor, ['inventory.package-state.view']);
        $operation = app(RecordPackageCheckpoint::class)($input, $actor);
        app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $unit->id, $input['location_id'], '1', 99101));
        $result = app(ReplayPackageCheckpoint::class)($operation->id, $actor);
        $this->assertFalse($result['matches_current_stock']);
        $this->assertSame('4.0000', $result['recorded_quantity']);
        $this->assertSame('3.0000', $result['current_quantity']);
        $this->assertContains('LATER_STOCK_MOVEMENT', $result['mismatch_reasons']);
        $this->assertContains('STOCK_QUANTITIES_CHANGED', $result['mismatch_reasons']);
    }

    public function test_equal_stock_after_receipt_and_reversal_does_not_make_old_evidence_fresh(): void
    {
        [$input, $actor, $item, $unit, $currency] = $this->checkpoint();
        PurchasingActor::grant($actor, ['inventory.package-state.view']);
        $operation = app(RecordPackageCheckpoint::class)($input, $actor);
        $receipt = $this->directReceipt($item->id, $unit->id, $input['location_id'], $currency, '1', '20', 99102);
        app(PostInventoryMovement::class)->reverseReceipt($receipt->source_type, $receipt->source_id, 'TEST_REVERSE', 99103, now());
        $result = app(ReplayPackageCheckpoint::class)($operation->id, $actor);
        $this->assertSame('4.0000', $result['current_quantity']);
        $this->assertFalse($result['matches_current_stock']);
        $this->assertContains('LATER_STOCK_MOVEMENT', $result['mismatch_reasons']);
        $this->assertNotContains('STOCK_QUANTITIES_CHANGED', $result['mismatch_reasons']);
    }

    public function test_a_later_checkpoint_supersedes_the_old_count_without_mutating_it(): void
    {
        [$input, $actor] = $this->checkpoint();
        PurchasingActor::grant($actor, ['inventory.package-state.view']);
        $first = app(RecordPackageCheckpoint::class)($input, $actor);
        $input['request_key'] = 'new-observation';
        $second = app(RecordPackageCheckpoint::class)($input, $actor);
        $old = app(ReplayPackageCheckpoint::class)($first->id, $actor);
        $new = app(ReplayPackageCheckpoint::class)($second->id, $actor);
        $this->assertContains('SUPERSEDED_CHECKPOINT', $old['mismatch_reasons']);
        $this->assertSame($second->id, $old['latest_checkpoint_id']);
        $this->assertTrue($new['matches_current_stock']);
        $this->assertSame('4.0000', $old['recorded_quantity']);
    }

    public function test_replay_uses_the_frozen_pack_name_after_archive_and_rename(): void
    {
        [$input, $actor, $item] = $this->checkpoint();
        PurchasingActor::grant($actor, ['inventory.package-state.view']);
        $pack = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'four', 'name' => 'Original four', 'stock_quantity' => '4', 'active' => true]);
        $input['composition'] = [['packaging_id' => $pack->id, 'count' => 1]];
        $operation = app(RecordPackageCheckpoint::class)($input, $actor);
        $pack->update(['name' => 'Renamed archived', 'active' => false]);
        $result = app(ReplayPackageCheckpoint::class)($operation->id, $actor);
        $this->assertSame('Original four', $result['composition'][0]['packaging_name']);
        $this->assertSame(1, $result['composition'][0]['sealed_count']);
        $this->assertTrue($result['matches_current_stock']);
    }

    public function test_legacy_checkpoint_without_watermark_remains_unknown(): void
    {
        [$input, $actor] = $this->checkpoint();
        PurchasingActor::grant($actor, ['inventory.package-state.view']);
        $operation = app(RecordPackageCheckpoint::class)($input, $actor);
        $header = $operation->getAttributes();
        unset($header['id']);
        $header['request_key'] = 'legacy-unknown';
        $header['stock_move_watermark'] = null;
        $legacy = PackageOperation::query()->create($header);
        $state = PackageState::query()->where('operation_id', $operation->id)->sole();
        $attributes = $state->getAttributes();
        unset($attributes['id']);
        $attributes['operation_id'] = $legacy->id;
        $copy = PackageState::query()->create($attributes);
        $part = PackageContribution::query()->where('state_id', $state->id)->sole()->getAttributes();
        unset($part['id']);
        $part['state_id'] = $copy->id;
        PackageContribution::query()->create($part);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $result = app(ReplayPackageCheckpoint::class)($legacy->id, $actor);
        $this->assertTrue($result['integrity_valid']);
        $this->assertFalse($result['matches_current_stock']);
        $this->assertContains('UNKNOWN_MOVEMENT_WATERMARK', $result['mismatch_reasons']);
    }

    public function test_count_creation_does_not_grant_package_evidence_view(): void
    {
        [$input, $actor] = $this->checkpoint();
        PurchasingActor::grant($actor, ['inventory.package-state.view']);
        $actor->revokePermissionTo('inventory.package-state.view');
        $operation = app(RecordPackageCheckpoint::class)($input, $actor);
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('view authority');
        app(ReplayPackageCheckpoint::class)($operation->id, $actor);
    }
}
