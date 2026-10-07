<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\ReplayPackageCheckpoint;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockMove;
use Database\Seeders\Demo\PackageIssueDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageIssueDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_issue_reconciles_costs_by_the_allocator_and_survives_later_progress(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $issue = PackageOperation::query()->where('request_key', 'demo-xyz-issue-12l')->sole();
        $snapshot = app(ReplayPackageCheckpoint::class)($issue->id, $actor);

        $this->assertTrue($snapshot['matches_current_stock']);
        $this->assertFalse($snapshot['tracking_active']);
        $this->assertSame('3.0000', $snapshot['open_loose_quantity']);
        $this->assertSame([1, null], array_column($snapshot['composition'], 'sealed_count'));
        $this->assertSame('8.0000', $snapshot['recorded_quantity']);
        $this->assertSame(['COUNTED', 'OPEN', 'ISSUE'], PackageOperation::query()->where('item_id', $issue->item_id)->orderBy('id')->pluck('evidence_kind')->all());
        $move = StockMove::query()->findOrFail($issue->stock_move_id);
        $this->assertSame('12.0000', $move->qty);
        $this->assertSame('-108.0000', number_format((float) $move->valuationEntries()->sum('base_amount'), 4, '.', ''));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $item = Item::query()->findOrFail($issue->item_id);
        app(PostInventoryMovement::class)->issue(['item_id' => $item->id, 'location_id' => $issue->location_id, 'qty' => '1', 'uom_id' => $item->stock_uom_id,
            'moved_at' => now(), 'source_type' => 'DIRECT_ISSUE', 'source_id' => 992104]);
        $before = [StockMove::query()->count(), PackageOperation::query()->get()->toArray()];
        $this->seed(PackageIssueDemoSeeder::class);
        $this->assertSame($before, [StockMove::query()->count(), PackageOperation::query()->get()->toArray()]);
        $this->assertFalse(app(ReplayPackageCheckpoint::class)($issue->id, $actor)['matches_current_stock'], 'a later scalar issue makes the evidence stale');
    }

    public function test_module_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(PackageIssueDemoSeeder::class)->run();
    }
}
