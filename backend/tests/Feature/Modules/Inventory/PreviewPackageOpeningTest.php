<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\PreviewPackageOpening;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchasingActor;

class PreviewPackageOpeningTest extends RecordPackageCheckpointTest
{
    public function test_opening_preserves_quantity_layers_and_all_database_state(): void
    {
        [$operation, $state, $actor] = $this->sealedCheckpoint();
        $before = [StockMove::query()->get()->toArray(), StockLayer::query()->get()->toArray(),
            DB::table('avco_pools')->get()->toArray(), PackageOperation::query()->count(), PackageState::query()->count()];
        $result = app(PreviewPackageOpening::class)($operation->id, $state->id, 1, 'OPEN', $actor);
        $this->assertFalse($result['posted']);
        $this->assertFalse($result['reserved']);
        $this->assertSame('4.0000', $result['quantity']);
        $this->assertSame(['SEALED', 'OPEN_LOOSE'], array_column($result['composition'], 'form'));
        $this->assertSame(['2.0000', '2.0000'], array_column($result['composition'], 'quantity'));
        $this->assertSame($before, [StockMove::query()->get()->toArray(), StockLayer::query()->get()->toArray(),
            DB::table('avco_pools')->get()->toArray(), PackageOperation::query()->count(), PackageState::query()->count()]);
    }

    public function test_unpacking_preserves_sealed_inner_packages_and_does_not_double_count(): void
    {
        [$operation, $state, $actor] = $this->sealedCheckpoint();
        $result = app(PreviewPackageOpening::class)($operation->id, $state->id, 1, 'UNPACK', $actor);
        $this->assertSame(['SEALED', 'SEALED'], array_column($result['composition'], 'form'));
        $this->assertSame([1, 20], array_column($result['composition'], 'sealed_count'));
        $this->assertSame(['carton', 'bottle'], array_column($result['composition'], 'packaging_code'));
        $this->assertSame(['2.0000', '2.0000'], array_column($result['composition'], 'quantity'));
    }

    public function test_all_packages_can_be_opened_without_zero_rows(): void
    {
        [$operation, $state, $actor] = $this->sealedCheckpoint();
        $result = app(PreviewPackageOpening::class)($operation->id, $state->id, 2, 'OPEN', $actor);
        $this->assertCount(1, $result['composition']);
        $this->assertSame('4.0000', $result['composition'][0]['quantity']);
    }

    public function test_stale_checkpoint_is_refused_after_stock_issue(): void
    {
        [$operation, $state, $actor, $item, $unit] = $this->sealedCheckpoint();
        app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $unit->id, $operation->location_id, '1', 99005));
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('fresh full-scope');
        app(PreviewPackageOpening::class)($operation->id, $state->id, 1, 'OPEN', $actor);
    }

    public function test_view_authority_is_required_even_for_preview(): void
    {
        [$operation, $state, $actor] = $this->sealedCheckpoint();
        $actor->revokePermissionTo('inventory.package-state.view');
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('view authority');
        app(PreviewPackageOpening::class)($operation->id, $state->id, 1, 'OPEN', $actor);
    }

    public function test_excess_count_is_refused(): void
    {
        [$operation, $state, $actor] = $this->sealedCheckpoint();
        $this->expectException(DomainInvariantException::class);
        app(PreviewPackageOpening::class)($operation->id, $state->id, 3, 'OPEN', $actor);
    }

    public function test_archived_and_renamed_definitions_preserve_outer_historical_identity(): void
    {
        [$operation, $state, $actor] = $this->sealedCheckpoint();
        ItemPackaging::query()->findOrFail($state->item_packaging_id)->update(['name' => 'New label', 'active' => false]);
        $result = app(PreviewPackageOpening::class)($operation->id, $state->id, 1, 'UNPACK', $actor);
        $this->assertSame('Two litre carton', $result['composition'][0]['packaging_name']);
    }

    public function test_selection_from_another_checkpoint_is_refused(): void
    {
        [$operation, $state, $actor] = $this->sealedCheckpoint();
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('does not belong');
        app(PreviewPackageOpening::class)($operation->id, $state->id + 1000, 1, 'OPEN', $actor);
    }

    protected function sealedCheckpoint(): array
    {
        [$input, $actor, $item, $unit] = $this->checkpoint();
        PurchasingActor::grant($actor, ['inventory.package-state.view']);
        $inner = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'bottle', 'name' => '100 mL bottle', 'stock_quantity' => '0.1', 'active' => true]);
        $outer = ItemPackaging::query()->create(['item_id' => $item->id, 'code' => 'carton', 'name' => 'Two litre carton', 'stock_quantity' => '2',
            'inner_packaging_id' => $inner->id, 'inner_count' => 20, 'active' => true]);
        $input['composition'] = [['packaging_id' => $outer->id, 'count' => 2]];
        $operation = app(RecordPackageCheckpoint::class)($input, $actor);

        return [$operation, PackageState::query()->where('operation_id', $operation->id)->sole(), $actor, $item, $unit];
    }
}
