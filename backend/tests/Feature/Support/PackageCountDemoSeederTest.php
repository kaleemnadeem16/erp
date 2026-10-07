<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Inventory\Actions\ReplayPackageCheckpoint;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockMove;
use Database\Seeders\Demo\PackageCountDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageCountDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_counts_replace_the_composition_then_reconcile_a_governed_loss(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $opening = PackageOperation::query()->where('request_key', 'demo-xyz-count-opening')->sole();
        $kinds = PackageOperation::query()->where('item_id', $opening->item_id)->orderBy('id')->pluck('evidence_kind')->all();
        $this->assertSame(['COUNTED', 'COUNTED', 'COUNT_VARIANCE'], $kinds, 'a recount without movement, then a loss with its governed issue');

        $latest = PackageOperation::query()->where('item_id', $opening->item_id)->orderByDesc('id')->firstOrFail();
        $snapshot = app(ReplayPackageCheckpoint::class)($latest->id, $actor);
        $this->assertTrue($snapshot['matches_current_stock']);
        $this->assertTrue($snapshot['tracking_active']);
        $this->assertSame('11.0000', $snapshot['recorded_quantity']);
        $this->assertSame('6.0000', $snapshot['open_loose_quantity']);
        $move = StockMove::query()->findOrFail($latest->stock_move_id);
        $this->assertSame(['ISSUE', '1.0000'], [$move->movement_kind, $move->qty]);
        $this->assertSame(1, StockMove::query()->where('item_id', $opening->item_id)->where('source_type', 'PHYSICAL_COUNT_LINE')->count(), 'the recount posted no movement');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $before = [StockMove::query()->count(), PackageOperation::query()->count()];
        $this->seed(PackageCountDemoSeeder::class);
        $this->assertSame($before, [StockMove::query()->count(), PackageOperation::query()->count()]);
    }

    public function test_module_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(PackageCountDemoSeeder::class)->run();
    }
}
