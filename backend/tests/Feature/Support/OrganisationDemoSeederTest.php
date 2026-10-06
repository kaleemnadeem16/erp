<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\Demo\OrganisationDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * OrganisationDemoSeeder claims a small tree whose two sites each own a demo warehouse, and two clerks whose role is limited to "only my
 * units". Each claim is proved through the real API on the stock the demo already holds, not by reading rows back.
 */
final class OrganisationDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    /** @return list<int> the warehouses the stock layers a person can read sit in */
    private function layerWarehouses(User $user): array
    {
        $locationIds = collect($this->actingAs($user)->getJson('/api/v1/stock-layers?per_page=100')->assertOk()->json('data'))->pluck('location_id')->unique()->all();

        return DB::table('locations')->whereIn('id', $locationIds)->pluck('warehouse_id')->unique()->sort()->values()->map(fn ($id): int => (int) $id)->all();
    }

    public function test_the_tree_and_the_ownership_of_the_demo_warehouses(): void
    {
        $this->assertEqualsCanonicalizing(['hq', 'main', 'khi'], OrganisationUnit::query()->pluck('code')->all());
        $hq = OrganisationUnit::query()->where('code', 'hq')->sole();
        $this->assertSame($hq->id, OrganisationUnit::query()->where('code', 'khi')->sole()->parent_id);
        $this->assertSame(['demo-main'], $this->ownedWarehouses('main'));
        $this->assertSame(['demo-khi'], $this->ownedWarehouses('khi'));
    }

    /** @return list<string> */
    private function ownedWarehouses(string $unitCode): array
    {
        $ids = OrganisationUnit::query()->where('code', $unitCode)->sole()->resources()->where('resource_type', 'warehouse')->pluck('resource_id');

        return Warehouse::query()->whereIn('id', $ids)->pluck('code')->all();
    }

    public function test_each_clerk_reaches_only_their_own_sites_stock_and_the_administrator_reaches_both(): void
    {
        $main = Warehouse::query()->where('code', 'demo-main')->value('id');
        $khi = Warehouse::query()->where('code', 'demo-khi')->value('id');

        $this->assertSame([(int) $main], $this->layerWarehouses($this->user(OrganisationDemoSeeder::MAIN_CLERK)));
        $this->assertSame([(int) $khi], $this->layerWarehouses($this->user(OrganisationDemoSeeder::KARACHI_CLERK)));
        $both = $this->layerWarehouses($this->user(config('demo.user.email')));
        $this->assertContains((int) $main, $both);
        $this->assertContains((int) $khi, $both);
    }

    public function test_a_limited_clerk_cannot_open_the_company_wide_overview_or_the_other_sites_counts(): void
    {
        $clerk = $this->user(OrganisationDemoSeeder::KARACHI_CLERK);
        $this->actingAs($clerk)->getJson('/api/v1/inventory-overview')->assertForbidden();
        $this->actingAs($clerk)->getJson('/api/v1/avco-pools')->assertForbidden();
        $mainId = (int) Warehouse::query()->where('code', 'demo-main')->value('id');
        $this->postJson('/api/v1/physical-counts', ['warehouse_id' => $mainId])->assertForbidden();
        $this->actingAs($this->user(config('demo.user.email')))->getJson('/api/v1/inventory-overview')->assertOk();
    }

    public function test_reseeding_neither_duplicates_nor_undoes_anything(): void
    {
        $limits = AccessScopeLimit::query()->count();
        $this->artisan('demo:seed')->assertSuccessful();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);

        $this->assertSame(3, OrganisationUnit::query()->count());
        $this->assertSame($limits, AccessScopeLimit::query()->count());
        $this->assertSame(1, $this->user(OrganisationDemoSeeder::MAIN_CLERK)->roles()->count());
    }
}
