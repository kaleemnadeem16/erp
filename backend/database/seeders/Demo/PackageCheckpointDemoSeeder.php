<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockLayer;
use App\Support\PermissionRegistry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/** One counted-evidence example; not active tracking or inferred receipt composition. */
final class PackageCheckpointDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(1);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $actor->givePermissionTo('inventory.package-state.view');
        $key = 'demo-package-counted-checkpoint';
        if (PackageOperation::query()->where('request_key', $key)->exists()) {
            return; // Preserve the immutable checkpoint after manual stock progress.
        }
        $item = Item::query()->where('code', 'demo-pack-thinner')->firstOrFail();
        $pack = ItemPackaging::query()->where('item_id', $item->id)->where('code', 'can-5l')->firstOrFail();
        $layers = StockLayer::query()->where('item_id', $item->id)->where('qty_remaining', '>', 0)->orderBy('received_at')->orderBy('id')->get();
        $location = $layers->firstOrFail()->location_id;
        $layers = $layers->where('location_id', $location);
        $total = BigDecimal::zero();
        foreach ($layers as $layer) {
            $total = $total->plus($layer->qty_remaining);
        }
        $content = BigDecimal::of($pack->stock_quantity);
        $count = $total->dividedBy($content, 0, RoundingMode::DOWN)->toInt();
        $loose = $total->minus($content->multipliedBy($count));
        $composition = $count > 0 ? [['packaging_id' => $pack->id, 'count' => $count]] : [];
        if ($loose->isGreaterThan(0)) {
            $composition[] = ['quantity' => (string) $loose];
        }
        // This fixture explicitly declares a fictional physical count; not historical receipt proof.
        app(RecordPackageCheckpoint::class)([
            'item_id' => $item->id, 'location_id' => $location, 'request_key' => $key,
            'reason' => 'Demo counted evidence: sealed 5 L cans plus combined loose contents. This is a fictional observed count, not inferred receipt provenance or active tracking.',
            'expected_quantity' => (string) $total,
            'expected_layers' => $layers->pluck('qty_remaining', 'id')->all(),
            'composition' => $composition,
        ], $actor);
    }
}
