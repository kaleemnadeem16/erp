<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Company;
use App\Models\Uom;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\DiscountAuthority\DiscountConfiguration;
use App\Support\CompanyContext;
use Database\Seeders\Demo\Sales\DiscountAuthorityDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Sales\Concerns\BuildsDiscountAuthority;
use Tests\TestCase;

class DiscountAuthorityDemoSeederTest extends TestCase
{
    use BuildsDiscountAuthority;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDiscountAuthority();
        Company::findOrFail(1)->update(['name' => 'X1 ERP Demo Company', 'code' => 'X1']);
        $uom = Uom::create(['code' => 'demo-ea', 'name' => 'Each', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'demo-service', 'name' => 'Demo service', 'default_valuation_method' => 'AVCO']);
        Item::create(['code' => 'demo-site-application', 'name' => 'DEMO ONLY service', 'type' => 'SERVICE', 'stock_uom_id' => $uom->id, 'category_id' => $category->id, 'is_sellable' => true, 'is_stocked' => false]);
        Party::create(['code' => 'cus-alpha', 'name' => 'DEMO ONLY Alpha']);
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
    }

    public function test_create_only_demo_does_not_activate_duties_grant_permissions_or_enable_authority(): void
    {
        $permissions = DB::table('model_has_permissions')->count();
        $active = app(SalesDutyManifest::class)->activeNames();
        app(DiscountAuthorityDemoSeeder::class)->run();
        $this->assertDatabaseCount('sales_discount_policy_versions', 1);
        $this->assertDatabaseCount('sales_discount_bands', 5);
        $this->assertDatabaseCount('sales_discount_assignments', 6);
        $this->assertDatabaseCount('sales_price_reference_versions', 2);
        $this->assertDatabaseCount('sales_price_reference_activations', 2);
        $this->assertSame('OFF', app(DiscountConfiguration::class)->mode());
        $this->assertSame($permissions, DB::table('model_has_permissions')->count());
        $this->assertSame($active, app(SalesDutyManifest::class)->activeNames());
        $this->assertSame(0, DB::table('users')->where('email', 'like', 'demo-only-authority-%')->where('must_change_password', false)->count());
        $snapshot = DB::table('sales_discount_policy_versions')->first();
        $people = DB::table('users')->count();
        app(DiscountAuthorityDemoSeeder::class)->run();
        $this->assertEquals($snapshot, DB::table('sales_discount_policy_versions')->first());
        $this->assertDatabaseCount('sales_discount_assignments', 6);
        $this->assertDatabaseCount('sales_price_reference_versions', 2);
        $this->assertSame($people, DB::table('users')->count());
    }

    public function test_a_real_company_name_is_not_a_demo_boundary_and_is_never_seeded(): void
    {
        Company::findOrFail(1)->update(['name' => 'Real customer']);
        app(DiscountAuthorityDemoSeeder::class)->run();
        $this->assertDatabaseCount('sales_discount_policy_versions', 0);
        $this->assertDatabaseCount('sales_price_reference_versions', 0);
    }

    public function test_production_is_refused_before_any_write(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(\RuntimeException::class);
        try {
            app(DiscountAuthorityDemoSeeder::class)->run();
        } finally {
            $this->assertDatabaseCount('sales_discount_policy_versions', 0);
        }
    }

    public function test_even_a_matching_demo_name_in_another_company_is_not_seeded(): void
    {
        $other = Company::create(['name' => 'X1 ERP Demo Company']);
        $this->app->instance(CompanyContext::class, new class((int) $other->id) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        // The central demo guard (issue #100) now refuses a non-demo company outright instead of returning quietly; nothing is written either way.
        $this->assertThrows(fn () => app(DiscountAuthorityDemoSeeder::class)->run(), \RuntimeException::class, 'designated demo company');
        $this->assertDatabaseCount('sales_discount_policy_versions', 0);
        $this->assertDatabaseCount('sales_price_reference_versions', 0);
    }
}
