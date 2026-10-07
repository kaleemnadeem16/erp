<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\ReplayPackageCheckpoint;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockMove;
use Database\Seeders\Demo\PackageActivationDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageActivationDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_item_is_grouped_and_every_scalar_writer_is_refused_while_the_chain_reconciles(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $issue = PackageOperation::query()->where('request_key', 'demo-xyz-activated-issue')->sole();
        $item = Item::query()->findOrFail($issue->item_id);

        $this->assertSame('PACKAGE_BALANCE', $item->package_tracking_mode->value);
        $snapshot = app(ReplayPackageCheckpoint::class)($issue->id, $actor);
        $this->assertTrue($snapshot['matches_current_stock']);
        $this->assertTrue($snapshot['tracking_active']);
        $this->assertSame('3.0000', $snapshot['open_loose_quantity']);
        $this->assertSame([2, null], array_column($snapshot['composition'], 'sealed_count'));
        $this->assertSame(['COUNTED', 'OPEN', 'ISSUE'], PackageOperation::query()->where('item_id', $item->id)->orderBy('id')->pluck('evidence_kind')->all());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        try {
            app(PostInventoryMovement::class)->issue(['item_id' => $item->id, 'location_id' => $issue->location_id, 'qty' => '1', 'uom_id' => $item->stock_uom_id,
                'moved_at' => now(), 'source_type' => 'DIRECT_ISSUE', 'source_id' => 994104]);
            $this->fail('A scalar issue must be refused for a grouped item.');
        } catch (DomainInvariantException $error) {
            $this->assertStringContainsString('grouped package tracking', $error->getMessage());
        }
        $before = [StockMove::query()->count(), PackageOperation::query()->count()];
        $this->seed(PackageActivationDemoSeeder::class);
        $this->assertSame($before, [StockMove::query()->count(), PackageOperation::query()->count()]);
    }

    public function test_module_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(PackageActivationDemoSeeder::class)->run();
    }
}
