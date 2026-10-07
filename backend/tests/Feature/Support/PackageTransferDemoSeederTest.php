<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\ReplayPackageCheckpoint;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockMove;
use Database\Seeders\Demo\PackageTransferDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageTransferDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_transfer_reconciles_both_depots_and_survives_later_progress(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $out = PackageOperation::query()->where('request_key', 'demo-xyz-transfer-7l')->sole();
        $in = PackageOperation::query()->where('request_key', 'demo-xyz-transfer-7l#in')->sole();
        $source = app(ReplayPackageCheckpoint::class)($out->id, $actor);
        $destination = app(ReplayPackageCheckpoint::class)($in->id, $actor);

        $this->assertTrue($source['matches_current_stock']);
        $this->assertTrue($destination['matches_current_stock']);
        $this->assertFalse($source['tracking_active']);
        $this->assertSame([1, null], array_column($source['composition'], 'sealed_count'));
        $this->assertSame('8.0000', $source['open_loose_quantity']);
        $this->assertSame([1, null], array_column($destination['composition'], 'sealed_count'));
        $this->assertSame('2.0000', $destination['open_loose_quantity'], 'the opened part arrives loose');
        $this->assertSame(['COUNTED', 'OPEN', 'TRANSFER_OUT'], PackageOperation::query()->where('location_id', $out->location_id)->where('item_id', $out->item_id)->orderBy('id')->pluck('evidence_kind')->all());
        $move = StockMove::query()->findOrFail($out->stock_move_id);
        $this->assertSame('7.0000', $move->qty);
        $this->assertSame('58.0000', number_format((float) $move->valuationEntries()->where('qty', '>', 0)->sum('base_amount'), 4, '.', ''));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $item = Item::query()->findOrFail($out->item_id);
        app(PostInventoryMovement::class)->issue(['item_id' => $item->id, 'location_id' => $out->location_id, 'qty' => '1', 'uom_id' => $item->stock_uom_id,
            'moved_at' => now(), 'source_type' => 'DIRECT_ISSUE', 'source_id' => 993104]);
        $before = [StockMove::query()->count(), PackageOperation::query()->get()->toArray()];
        $this->seed(PackageTransferDemoSeeder::class);
        $this->assertSame($before, [StockMove::query()->count(), PackageOperation::query()->get()->toArray()]);
        $this->assertFalse(app(ReplayPackageCheckpoint::class)($out->id, $actor)['matches_current_stock']);
    }

    public function test_module_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(PackageTransferDemoSeeder::class)->run();
    }
}
