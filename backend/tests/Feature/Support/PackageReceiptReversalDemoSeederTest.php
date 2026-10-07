<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Inventory\Actions\ReplayPackageCheckpoint;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use Database\Seeders\Demo\PackageReceiptReversalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageReceiptReversalDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_reverses_the_wrong_delivery_with_exact_evidence_and_keeps_the_other(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $count = PackageOperation::query()->where('request_key', 'demo-xyz-reversal-count')->sole();
        $kinds = PackageOperation::query()->where('item_id', $count->item_id)->orderBy('id')->pluck('evidence_kind')->all();
        $this->assertSame(['COUNTED', 'RECEIPT_IN', 'RECEIPT_IN', 'RECEIPT_REVERSAL'], $kinds);

        $reversal = PackageOperation::query()->where('evidence_kind', 'RECEIPT_REVERSAL')->where('item_id', $count->item_id)->sole();
        $snapshot = app(ReplayPackageCheckpoint::class)($reversal->id, $actor);
        $this->assertTrue($snapshot['matches_current_stock']);
        $this->assertSame('20.0000', $snapshot['recorded_quantity'], '15 L opening stock plus the delivery that was kept');
        $this->assertSame([4], array_column($snapshot['composition'], 'sealed_count'));
        $this->assertSame('REVERSAL', StockMove::query()->findOrFail($reversal->stock_move_id)->movement_kind);
        $this->assertSame(1, GoodsReceipt::query()->where('status', GoodsReceiptStatus::Reversed)->where('idempotency_key', 'demo-xyz-reversal-wrong')->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $before = [StockMove::query()->count(), PackageOperation::query()->count()];
        $this->seed(PackageReceiptReversalDemoSeeder::class);
        $this->assertSame($before, [StockMove::query()->count(), PackageOperation::query()->count()]);
    }

    public function test_module_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(PackageReceiptReversalDemoSeeder::class)->run();
    }
}
