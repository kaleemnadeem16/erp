<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_complete_idempotent_demo_dataset(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        $demoKeys = [
            'currencies' => ['PKR', 'USD', 'AED'],
            'uoms' => ['ea', 'kg', 'g', 'l', 'ml', 'm', 'sqm', 'hr'],
            'chart_of_accounts' => ['1100', '1200', '2100', '4000', '5000', '5100'],
            'parties' => ['sup-punjab', 'sup-pakchem', 'sup-coatings', 'cus-alpha', 'cus-metro'],
            'categories' => ['demo-raw', 'demo-pigments', 'demo-solvents', 'demo-finished', 'demo-services', 'demo-equipment'],
            'items' => ['demo-pig-blue', 'demo-pig-red', 'demo-thinner', 'demo-paint-white', 'demo-site-application', 'demo-painter-hour', 'demo-spray-unit'],
            'warehouses' => ['demo-main', 'demo-khi'],
        ];

        $idsBeforeRerun = [];
        foreach ($demoKeys as $table => $codes) {
            $idsBeforeRerun[$table] = $this->demoIds($table, $codes);
            $this->assertCount(count($codes), $idsBeforeRerun[$table]);
        }
        $this->assertSame(3, (int) DB::table('supplier_accounts')->whereIn('code', ['sup-punjab', 'sup-pakchem', 'sup-coatings'])->count());
        $this->assertSame(2, (int) DB::table('customer_accounts')->whereIn('code', ['cus-alpha', 'cus-metro'])->count());
        $this->assertSame(5, (int) DB::table('item_suppliers')->whereIn('item_id', $idsBeforeRerun['items'])->count());

        $user = User::query()->where('email', 'user@erp.x1techs.com')->firstOrFail();
        $this->assertTrue(Hash::check('!User@Erp@123', $user->password));

        $this->artisan('demo:seed')->assertSuccessful();

        foreach ($demoKeys as $table => $codes) {
            $this->assertSame($idsBeforeRerun[$table], $this->demoIds($table, $codes));
        }
    }

    public function test_rerun_preserves_immutable_override_requests_after_live_stock_changes(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        $requestsBeforeStockChange = DB::table('negative_stock_override_requests')
            ->where('source_type', 'DEMO_JOB_ISSUE')
            ->orderBy('source_id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $this->assertCount(2, $requestsBeforeStockChange);

        $item = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();
        $location = Location::query()
            ->where('code', 'stock')
            ->whereHas('warehouse', fn ($query) => $query->where('code', 'demo-main'))
            ->firstOrFail();
        $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
        $onHandBeforeStockChange = (float) StockLayer::query()
            ->where('item_id', $item->getKey())
            ->where('location_id', $location->getKey())
            ->whereNull('batch_id')
            ->whereNull('serial_no')
            ->sum('qty_remaining');
        $latestMovementAt = StockMove::query()
            ->where('item_id', $item->getKey())
            ->max('moved_at');

        app(PostInventoryMovement::class)([
            'item_id' => $item->getKey(),
            'location_id' => $location->getKey(),
            'qty' => '7.0000',
            'uom_id' => $item->stock_uom_id,
            'moved_at' => Carbon::parse($latestMovementAt)->addSecond(),
            'source_type' => 'TEST_DEMO_STOCK_DRIFT',
            'source_id' => 1,
            'unit_cost' => '500.0000',
            'currency_id' => $currency->getKey(),
            'fx_rate' => '1.000000000',
            'base_unit_cost' => '500.0000',
        ]);

        $onHandAfterStockChange = (float) StockLayer::query()
            ->where('item_id', $item->getKey())
            ->where('location_id', $location->getKey())
            ->whereNull('batch_id')
            ->whereNull('serial_no')
            ->sum('qty_remaining');
        $this->assertSame($onHandBeforeStockChange + 7.0, $onHandAfterStockChange);

        $this->artisan('demo:seed')->assertSuccessful();

        $requestsAfterRerun = DB::table('negative_stock_override_requests')
            ->where('source_type', 'DEMO_JOB_ISSUE')
            ->orderBy('source_id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $this->assertSame($requestsBeforeStockChange, $requestsAfterRerun);

        // AssemblyVersionsDemoSeeder is the final seeder in the full chain:
        // reaching its component rows proves the stock fixture did not block
        // any of the later Estimating demo data.
        $this->assertDatabaseHas('assemblies', ['code' => 'demo-asm-wall-paint']);
        $this->assertSame(5, DB::table('assembly_components')->count());
    }

    public function test_the_command_refuses_to_run_in_production(): void
    {
        $originalEnvironment = $this->app->environment();
        $usersBefore = User::query()->count();
        $itemsBefore = DB::table('items')->count();

        try {
            $this->app->detectEnvironment(fn (): string => 'production');

            $this->artisan('demo:seed')
                ->expectsOutputToContain('Demo data may only be seeded in local or testing environments.')
                ->assertFailed();
        } finally {
            $this->app->detectEnvironment(fn (): string => $originalEnvironment);
        }

        $this->assertSame($usersBefore, User::query()->count());
        $this->assertSame($itemsBefore, DB::table('items')->count());
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, int>
     */
    private function demoIds(string $table, array $codes): array
    {
        return DB::table($table)
            ->whereIn('code', $codes)
            ->orderBy('code')
            ->pluck('id', 'code')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
