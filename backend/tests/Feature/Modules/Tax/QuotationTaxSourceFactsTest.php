<?php

namespace Tests\Feature\Modules\Tax;

use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Tax\Actions\DeclareQuotationTaxSource;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\Quotation\QuotationTaxSourceFacts;
use App\Modules\Tax\Support\TaxDutyManifest;
use App\Modules\Tax\Support\TaxNature;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Database\Seeders\Demo\QuotationTaxFactsDemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class QuotationTaxSourceFactsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->actor = User::factory()->create();
        Permission::findOrCreate(TaxDutyManifest::CONFIGURE, 'web');
        $this->actor->givePermissionTo(TaxDutyManifest::CONFIGURE);
        $this->app->instance(TaxDutyManifest::class, new TaxDutyManifest([TaxDutyManifest::CONFIGURE => 'ACTIVE']));
        $uom = Uom::create(['code' => 'hr', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'svc', 'name' => 'Services', 'default_valuation_method' => 'AVCO']);
        $this->item = Item::create(['code' => 'svc', 'name' => 'Service', 'type' => ItemType::Service, 'stock_uom_id' => $uom->id, 'category_id' => $category->id, 'is_sellable' => true]);
    }

    private function generation(): int
    {
        return (int) DB::table('tax_generations')->where('company_id', 1)->value('generation');
    }

    public function test_explicit_facts_are_scoped_effective_and_never_inferred(): void
    {
        $facts = app(QuotationTaxSourceFacts::class);
        $this->assertNull($facts->jurisdiction('2026-10-29'));
        $this->assertNull($facts->item($this->item->id, '2026-10-29'));
        $action = app(DeclareQuotationTaxSource::class);
        $period = new EffectivePeriod('2026-01-01', '2027-01-01');
        $jurisdictionId = $action->jurisdiction($this->actor, 'illustrative', $period, 'Illustrative evidence', 'jurisdiction', $this->generation());
        $itemId = $action->item($this->actor, $this->item->id, TaxNature::Services, 'demo-service', $period, 'Illustrative evidence', 'item', $this->generation());
        $this->assertSame($jurisdictionId, $action->jurisdiction($this->actor, 'illustrative', $period, 'Illustrative evidence', 'jurisdiction', 0));
        $this->assertSame($itemId, $action->item($this->actor, $this->item->id, TaxNature::Services, 'demo-service', $period, 'Illustrative evidence', 'item', 0));
        $this->assertSame('illustrative', $facts->jurisdiction('2026-10-29'));
        $classification = $facts->item($this->item->id, '2026-10-29');
        $this->assertSame(TaxNature::Services, $classification?->nature);
        $this->assertSame('demo-service', $classification?->code);
        $this->assertSame('item_explicit', $classification?->provenance);
        $this->assertNull($facts->jurisdiction('2027-01-01'));
        $this->assertNull($facts->item($this->item->id, '2027-01-01'));
        $company = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $company) {}

            public function id(): int
            {
                return $this->company;
            }
        });
        $this->assertNull($facts->jurisdiction('2026-10-29'));
        $this->assertNull($facts->item($this->item->id, '2026-10-29'));
    }

    public function test_staged_or_missing_permission_refuses_configuration(): void
    {
        $this->app->instance(TaxDutyManifest::class, new TaxDutyManifest);
        $this->expectException(AuthorizationException::class);
        app(DeclareQuotationTaxSource::class)->jurisdiction($this->actor, 'demo', new EffectivePeriod('2026-01-01'), 'Evidence', 'one', 0);
    }

    public function test_demo_facts_are_local_only_idempotent_and_illustrative(): void
    {
        config(['demo.user.email' => $this->actor->email]);
        $this->item->update(['code' => 'demo-site-application']);
        $this->seed(QuotationTaxFactsDemoSeeder::class);
        $this->seed(QuotationTaxFactsDemoSeeder::class);
        $this->assertSame(1, DB::table('tax_quotation_jurisdictions')->count());
        $this->assertSame(1, DB::table('tax_quotation_item_classifications')->count());
        $this->assertSame('demo-illustrative', app(QuotationTaxSourceFacts::class)->jurisdiction('2026-10-29'));
        $this->assertSame('demo-service', app(QuotationTaxSourceFacts::class)->item($this->item->id, '2026-10-29')?->code);
    }

    public function test_database_refuses_overlap_and_mutation(): void
    {
        $action = app(DeclareQuotationTaxSource::class);
        $action->jurisdiction($this->actor, 'demo', new EffectivePeriod('2026-01-01', '2027-01-01'), 'Evidence', 'one', 0);
        try {
            DB::transaction(fn () => DB::table('tax_quotation_jurisdictions')->insert([
                'company_id' => 1, 'jurisdiction' => 'other', 'effective_from' => '2026-06-01', 'created_by' => $this->actor->id, 'reason' => 'x',
            ]));
            $this->fail('Overlapping jurisdiction facts must refuse.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => DB::table('tax_quotation_jurisdictions')->update(['jurisdiction' => 'rewritten']));
            $this->fail('Historical source facts must not be rewritten.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->assertSame('demo', app(QuotationTaxSourceFacts::class)->jurisdiction('2026-10-29'));
    }
}
