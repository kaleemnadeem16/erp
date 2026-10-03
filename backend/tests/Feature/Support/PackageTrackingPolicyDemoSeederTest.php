<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\PackageTrackingMode;
use Database\Seeders\Demo\PackageTrackingPolicyDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageTrackingPolicyDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_records_all_creation_sources_and_preserves_manual_progress(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $expected = [
            'demo-package-policy-company-item' => 'COMPANY',
            'demo-package-policy-category-item' => 'CATEGORY',
            'demo-package-policy-explicit-item' => 'ITEM',
        ];
        foreach ($expected as $code => $source) {
            $item = Item::query()->where('code', $code)->sole();
            $this->assertSame(PackageTrackingMode::QuantityOnly, $item->package_tracking_mode);
            $this->assertSame($source, $item->package_tracking_source);
            $this->assertFalse($item->is_stocked);
        }
        $edited = Item::query()->where('code', 'demo-package-policy-explicit-item')->sole();
        $edited->update(['name' => 'Manual demonstration name']);
        $this->seed(PackageTrackingPolicyDemoSeeder::class);
        $this->assertSame('Manual demonstration name', $edited->refresh()->name);
        $this->assertSame('ITEM', $edited->package_tracking_source);
        $this->assertSame(3, Item::query()->whereIn('code', array_keys($expected))->count());
    }

    public function test_module_rejects_production_even_when_called_directly(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(PackageTrackingPolicyDemoSeeder::class)->run();
    }
}
