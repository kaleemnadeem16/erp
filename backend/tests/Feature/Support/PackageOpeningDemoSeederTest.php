<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\PreviewPackageOpening;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockMove;
use Database\Seeders\Demo\PackageOpeningDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageOpeningDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_spans_layers_and_preserves_manual_progress_on_rerun(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $operation = PackageOperation::query()->where('request_key', 'demo-package-opening-checkpoint')->sole();
        $state = PackageState::query()->where('operation_id', $operation->id)->sole();
        $result = app(PreviewPackageOpening::class)($operation->id, $state->id, 1, 'OPEN', $actor);
        $loose = collect($result['composition'])->firstWhere('form', 'OPEN_LOOSE');
        $this->assertSame(['1.0000', '1.0000'], array_column($loose['contributions'], 'quantity'));
        $unpack = app(PreviewPackageOpening::class)($operation->id, $state->id, 1, 'UNPACK', $actor);
        $this->assertSame([1, 20], array_column($unpack['composition'], 'sealed_count'));
        $item = Item::query()->findOrFail($operation->item_id);
        app(PostInventoryMovement::class)->issue(['item_id' => $item->id, 'location_id' => $operation->location_id,
            'qty' => '1', 'uom_id' => $item->stock_uom_id, 'moved_at' => now(), 'source_type' => 'DIRECT_ISSUE', 'source_id' => 991003]);
        $before = [StockMove::query()->count(), PackageOperation::query()->get()->toArray()];
        $this->seed(PackageOpeningDemoSeeder::class);
        $this->assertSame($before, [StockMove::query()->count(), PackageOperation::query()->get()->toArray()]);
    }

    public function test_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(PackageOpeningDemoSeeder::class)->run();
    }
}
