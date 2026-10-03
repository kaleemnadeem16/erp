<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Inventory\Actions\ReplayPackageCheckpoint;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use Database\Seeders\Demo\PackageCheckpointDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageCheckpointDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_records_real_checkpoint_and_preserves_it_on_rerun(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $operation = PackageOperation::query()->where('request_key', 'demo-package-counted-checkpoint')->sole();
        $this->assertSame('COUNTED', $operation->evidence_kind);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $replay = app(ReplayPackageCheckpoint::class)($operation->id, $actor);
        $this->assertTrue($replay['matches_current_stock']);
        $this->assertFalse($replay['tracking_active']);
        $this->assertStringContainsString('fictional observed count', $operation->reason);
        $this->assertTrue(PackageState::query()->where('operation_id', $operation->id)->where('form', 'SEALED')->exists());
        $this->assertTrue(PackageState::query()->where('operation_id', $operation->id)->where('form', 'OPEN_LOOSE')->exists());
        $before = PackageState::query()->where('operation_id', $operation->id)->get()->toArray();
        $this->seed(PackageCheckpointDemoSeeder::class);
        $this->assertSame(1, PackageOperation::query()->where('request_key', 'demo-package-counted-checkpoint')->count());
        $this->assertSame($before, PackageState::query()->where('operation_id', $operation->id)->get()->toArray());
    }

    public function test_module_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(PackageCheckpointDemoSeeder::class)->run();
    }
}
