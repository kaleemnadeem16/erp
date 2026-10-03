<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\ReplayPackageCheckpoint;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockMove;
use Database\Seeders\Demo\PackageOpeningOperationsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageOpeningOperationsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_demo_reconciles_and_preserves_later_manual_stock_progress(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $latest = PackageOperation::query()->where('request_key', 'demo-saved-open-2')->sole();
        $snapshot = app(ReplayPackageCheckpoint::class)($latest->id, $actor);
        $this->assertTrue($snapshot['matches_current_stock']);
        $this->assertFalse($snapshot['tracking_active']);
        $this->assertSame('0.2000', $snapshot['open_loose_quantity']);
        $this->assertSame([1, 18, null], array_column($snapshot['composition'], 'sealed_count'));
        $this->assertSame('4.0000', $snapshot['recorded_quantity']);
        $this->assertSame(['COUNTED', 'UNPACK', 'OPEN', 'OPEN'], PackageOperation::query()->where('item_id', $latest->item_id)->orderBy('id')->pluck('evidence_kind')->all());
        // Also prove commit-time database constraints against this chained, multi-layer fixture.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $item = Item::query()->findOrFail($latest->item_id);
        app(PostInventoryMovement::class)->issue(['item_id' => $item->id, 'location_id' => $latest->location_id,
            'qty' => '0.1', 'uom_id' => $item->stock_uom_id, 'moved_at' => now(), 'source_type' => 'DIRECT_ISSUE', 'source_id' => 991103]);
        $before = [StockMove::query()->count(), PackageOperation::query()->get()->toArray()];
        $this->seed(PackageOpeningOperationsDemoSeeder::class);
        $this->assertSame($before, [StockMove::query()->count(), PackageOperation::query()->get()->toArray()]);
        $this->assertFalse(app(ReplayPackageCheckpoint::class)($latest->id, $actor)['matches_current_stock']);
    }

    public function test_module_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(PackageOpeningOperationsDemoSeeder::class)->run();
    }
}
