<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Inventory\Actions\ReadPackageBalances;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockMove;
use Database\Seeders\Demo\PackageQaDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageQaDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_releases_part_of_a_held_delivery_and_leaves_the_rest_held(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $count = PackageOperation::query()->where('request_key', 'demo-xyz-qa-count')->sole();
        $this->assertSame(['COUNTED', 'RECEIPT_IN', 'QA_DISPOSITION'], PackageOperation::query()->where('item_id', $count->item_id)->orderBy('id')->pluck('evidence_kind')->all());

        $balance = collect(app(ReadPackageBalances::class)($count->item_id, null, null, $actor))->sole();
        $this->assertSame(['27.0000', '22.0000', '5.0000', '0.0000'], [$balance['total'], $balance['usable_total'], $balance['held']['total'], $balance['rejected']['total']]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $before = [StockMove::query()->count(), PackageOperation::query()->count()];
        $this->seed(PackageQaDemoSeeder::class);
        $this->assertSame($before, [StockMove::query()->count(), PackageOperation::query()->count()]);
    }

    public function test_module_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(PackageQaDemoSeeder::class)->run();
    }
}
