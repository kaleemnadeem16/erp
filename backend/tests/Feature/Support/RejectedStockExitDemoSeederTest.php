<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Inventory\Actions\ReadPackageBalances;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\RejectedStockExit;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Models\StockScrap;
use App\Modules\Purchasing\Models\SupplierReturn;
use Database\Seeders\Demo\RejectedStockExitDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RejectedStockExitDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_returns_one_can_scraps_part_of_the_rest_and_leaves_loose_litres_and_a_draft_waiting(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();

        $grouped = Item::query()->where('code', 'demo-rejected-exit')->firstOrFail();
        $balance = collect(app(ReadPackageBalances::class)($grouped->id, null, null, $actor))->sole();
        // 15 L opening + 19 L delivery, minus a returned can (5) and 7 L scrapped; 2 L loose stay rejected, awaiting scrap.
        $this->assertSame(['22.0000', '2.0000'], [$balance['total'], $balance['rejected']['total']]);
        $this->assertSame('2.0000', $balance['rejected']['loose']);
        $this->assertSame([], $balance['rejected']['sealed']);

        $return = SupplierReturn::query()->where('status', 'POSTED')->sole();
        $scrap = StockScrap::query()->where('status', 'POSTED')->sole();
        $this->assertStringStartsWith('SRN-', (string) $return->number);
        $this->assertStringStartsWith('SCR-', (string) $scrap->number);
        $this->assertSame(2, RejectedStockExit::query()->count());
        $draft = SupplierReturn::query()->where('status', 'DRAFT')->sole();
        $this->assertSame('4.0000', (string) $draft->lines()->sole()->quantity);

        $qty = Item::query()->where('code', 'demo-rejected-exit-qty')->firstOrFail();
        $this->assertSame(6.0, (float) DB::table('stock_layers')->where('item_id', $qty->id)->sum('qty_remaining') - 4.0); // 6 usable + 4 rejected on hand
        $this->assertSame(1, PackageOperation::query()->where('request_key', 'demo-xyz-exit-count')->count());
    }

    public function test_demo_personas_prepare_post_and_are_scope_limited(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $preparer = User::query()->where('email', RejectedStockExitDemoSeeder::PREPARER)->firstOrFail();
        $controller = User::query()->where('email', RejectedStockExitDemoSeeder::CONTROLLER)->firstOrFail();
        $clerk = User::query()->where('email', RejectedStockExitDemoSeeder::SITE_CLERK)->firstOrFail();

        $this->assertTrue($preparer->can('purchasing.supplier-return.create'));
        $this->assertFalse($preparer->can('purchasing.supplier-return.post'));
        $this->assertFalse($preparer->can('inventory.scrap.post'));
        $this->assertTrue($controller->can('inventory.scrap.post'));
        $this->assertTrue($controller->can('inventory.valuation.view'));
        $this->assertFalse($clerk->can('inventory.valuation.view'));
        $this->assertSame(AccessScopeLimit::MY_UNITS, AccessScopeLimit::query()->where('user_id', $clerk->id)->sole()->scope);
    }

    public function test_rerun_changes_nothing(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $before = [StockMove::query()->count(), PackageOperation::query()->count(), SupplierReturn::query()->count(), StockScrap::query()->count(), User::query()->count()];
        $this->seed(RejectedStockExitDemoSeeder::class);
        $this->assertSame($before, [StockMove::query()->count(), PackageOperation::query()->count(), SupplierReturn::query()->count(), StockScrap::query()->count(), User::query()->count()]);
    }

    public function test_module_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(RejectedStockExitDemoSeeder::class)->run();
    }
}
