<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\ValuationMethod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Storage foundation only; raw fixtures do not activate tracked stock. */
class PackageKernelStorageTest extends StockIssueTest
{
    public function test_snapshot_reconciles_and_remains_immutable(): void
    {
        [$operation, $layer] = $this->foundation();
        $state = $this->state($operation, '4');
        $this->contribute($state, $layer, '4');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame('4.0000', DB::table('inventory_package_contributions')->where('state_id', $state)->sum('quantity'));
        $this->assertSame(1, PackageOperation::query()->count());
        $this->expectException(QueryException::class);
        DB::table('inventory_package_operations')->where('id', $operation)->update(['reason' => 'rewritten']);
    }

    public function test_database_refuses_incomplete_contribution_totals(): void
    {
        [$operation, $layer] = $this->foundation();
        $state = $this->state($operation, '4');
        $this->contribute($state, $layer, '3');
        $this->expectException(QueryException::class);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_database_refuses_a_state_without_contributions(): void
    {
        [$operation] = $this->foundation();
        $this->state($operation, '4');
        $this->expectException(QueryException::class);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_database_refuses_layer_from_another_location(): void
    {
        [$operation, $layer] = $this->foundation();
        $other = $this->otherLocationId('kernel-other');
        DB::table('inventory_package_operations')->insert([
            'company_id' => 1, 'item_id' => StockLayer::findOrFail($layer)->item_id,
            'location_id' => $other, 'request_key' => 'other', 'payload_hash' => str_repeat('b', 64),
            'evidence_kind' => 'COUNTED', 'actor_id' => User::query()->firstOrFail()->id,
            'reason' => 'counted checkpoint', 'recorded_at' => now(),
        ]);
        $second = DB::table('inventory_package_operations')->where('request_key', 'other')->value('id');
        $state = $this->state($second, '4');
        $this->expectException(QueryException::class);
        $this->contribute($state, $layer, '4');
    }

    public function test_request_identity_is_unique_within_company(): void
    {
        [$operation] = $this->foundation();
        $input = PackageOperation::findOrFail($operation)->getAttributes();
        unset($input['id']);
        $this->expectException(QueryException::class);
        DB::table('inventory_package_operations')->insert($input);
    }

    public function test_outer_transaction_rolls_back_all_kernel_rows(): void
    {
        [$operation, $layer] = $this->foundation();
        try {
            DB::transaction(function () use ($operation, $layer): void {
                $state = $this->state($operation, '4');
                $this->contribute($state, $layer, '4');
                throw new \RuntimeException('injected failure');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('injected failure', $error->getMessage());
        }
        $this->assertSame(0, DB::table('inventory_package_states')->count());
        $this->assertSame(0, DB::table('inventory_package_contributions')->count());
    }

    public function test_cross_layer_contributions_can_complete_one_snapshot(): void
    {
        [$operation, $first] = $this->foundation();
        $layer = StockLayer::findOrFail($first);
        $item = Item::findOrFail($layer->item_id);
        $this->directReceipt($item->id, $item->stock_uom_id, $layer->location_id, $layer->currency_id, '3', '20', 98002);
        $second = StockLayer::query()->where('source_id', 98002)->sole();
        $state = $this->state($operation, '4');
        $this->contribute($state, $first, '1');
        DB::table('inventory_package_contributions')->insert([
            'company_id' => 1, 'state_id' => $state, 'stock_layer_id' => $second->id,
            'position' => 2, 'quantity' => '3',
        ]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame(2, DB::table('inventory_package_contributions')->where('state_id', $state)->count());
    }

    public function test_database_refuses_sealed_form_without_identity_and_count(): void
    {
        [$operation] = $this->foundation();
        $this->expectException(QueryException::class);
        DB::table('inventory_package_states')->insert([
            'company_id' => 1, 'operation_id' => $operation, 'form' => 'SEALED',
            'availability' => 'USABLE', 'stock_quantity' => '4',
        ]);
    }

    public function test_snapshot_rows_cannot_be_deleted_by_raw_sql(): void
    {
        [$operation, $layer] = $this->foundation();
        $state = $this->state($operation, '4');
        $this->contribute($state, $layer, '4');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('immutable');
        DB::table('inventory_package_states')->where('id', $state)->delete();
    }

    /** @return array{int,int} */
    private function foundation(): array
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo);
        $location = $this->locationId();
        $this->directReceipt($item->id, $unit->id, $location, $order->currency_id, '4', '10', 98001);
        $layer = StockLayer::query()->sole();
        $operation = PackageOperation::query()->create([
            'item_id' => $item->id, 'location_id' => $location, 'request_key' => 'kernel-checkpoint',
            'payload_hash' => str_repeat('a', 64), 'evidence_kind' => 'COUNTED',
            'actor_id' => User::query()->firstOrFail()->id, 'reason' => 'Counted composition, tracking disabled',
            'recorded_at' => now(),
        ]);

        return [$operation->id, $layer->id];
    }

    private function state(int $operation, string $qty): int
    {
        return DB::table('inventory_package_states')->insertGetId([
            'company_id' => 1, 'operation_id' => $operation, 'form' => 'OPEN_LOOSE',
            'availability' => 'USABLE', 'stock_quantity' => $qty,
        ]);
    }

    private function contribute(int $state, int $layer, string $qty): void
    {
        DB::table('inventory_package_contributions')->insert([
            'company_id' => 1, 'state_id' => $state, 'stock_layer_id' => $layer,
            'position' => 1, 'quantity' => $qty,
        ]);
    }
}
