<?php

namespace Tests\Feature\Modules\Tax;

use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Tax\Actions\CorrectQuotationTaxSource;
use App\Modules\Tax\Actions\DeclareQuotationTaxSource;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\Quotation\QuotationTaxSourceFacts;
use App\Modules\Tax\Support\TaxDutyManifest;
use App\Modules\Tax\Support\TaxNature;
use App\Modules\Tax\Support\TaxRefusal;
use App\Support\UomDimension;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Correcting a quotation tax source fact is a NEW immutable version, never an edit: the original and earlier versions stay, the reader uses the latest for the fact's own period,
 * the Tax generation moves, retries replay, and every guard of the Tax writers (staged duty, company scope, idempotency, append-only, no truncate) holds.
 */
class QuotationTaxSourceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Item $item;

    private int $jurisdiction;

    private int $classification;

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
        $declare = app(DeclareQuotationTaxSource::class);
        $period = new EffectivePeriod('2026-01-01', '2027-01-01');
        $this->jurisdiction = $declare->jurisdiction($this->actor, 'wrong-place', $period, 'Illustrative', 'j', $this->generation());
        $this->classification = $declare->item($this->actor, $this->item->id, TaxNature::Goods, 'wrong-class', $period, 'Illustrative', 'i', $this->generation());
    }

    private function generation(): int
    {
        return (int) DB::table('tax_generations')->where('company_id', 1)->value('generation');
    }

    public function test_a_correction_supersedes_for_the_facts_period_and_every_earlier_version_stays(): void
    {
        $facts = app(QuotationTaxSourceFacts::class);
        $correct = app(CorrectQuotationTaxSource::class);
        $this->assertSame('wrong-place', $facts->jurisdiction('2026-10-29'));
        $before = $this->generation();

        $v1 = $correct->jurisdiction($this->actor, $this->jurisdiction, 'right-place', 'Mis-keyed at set-up', 'c1', $before);
        $this->assertGreaterThan($before, $this->generation(), 'a correction moves the Tax generation, so an earlier determination is provably stale');
        $this->assertSame('right-place', $facts->jurisdiction('2026-10-29'));
        $this->assertNull($facts->jurisdiction('2027-01-01'), 'a correction does not extend the fact\'s period');
        $v2 = $correct->jurisdiction($this->actor, $this->jurisdiction, 'better-place', 'Second look', 'c2', $this->generation());

        $rows = DB::table('tax_quotation_source_corrections')->where('jurisdiction_fact_id', $this->jurisdiction)->orderBy('version_no')->get();
        $this->assertSame([1, 2], $rows->pluck('version_no')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([null, $v1], $rows->pluck('predecessor_id')->map(fn ($v) => $v === null ? null : (int) $v)->all());
        $this->assertSame([$v1, $v2], $rows->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('wrong-place', DB::table('tax_quotation_jurisdictions')->where('id', $this->jurisdiction)->value('jurisdiction'), 'the original fact is untouched');
        $this->assertSame('better-place', $facts->jurisdiction('2026-10-29'));
    }

    public function test_an_item_classification_is_corrected_the_same_way(): void
    {
        $facts = app(QuotationTaxSourceFacts::class);
        $this->assertSame('wrong-class', $facts->item($this->item->id, '2026-10-29')?->code);

        app(CorrectQuotationTaxSource::class)->item($this->actor, $this->classification, TaxNature::Services, 'right-class', 'It is a service', 'c1', $this->generation());

        $now = $facts->item($this->item->id, '2026-10-29');
        $this->assertSame([TaxNature::Services, 'right-class', 'item_explicit'], [$now?->nature, $now?->code, $now?->provenance]);
        $this->assertSame('wrong-class', DB::table('tax_quotation_item_classifications')->where('id', $this->classification)->value('classification'));
    }

    public function test_a_retry_replays_a_changed_intent_conflicts_and_stale_or_unchanged_input_is_refused(): void
    {
        $correct = app(CorrectQuotationTaxSource::class);
        $generation = $this->generation();
        $id = $correct->jurisdiction($this->actor, $this->jurisdiction, 'right-place', 'Fix', 'same', $generation);
        $this->assertSame($id, $correct->jurisdiction($this->actor, $this->jurisdiction, 'right-place', 'Fix', 'same', $generation));
        $this->assertSame(1, DB::table('tax_quotation_source_corrections')->count());

        foreach ([
            [fn () => $correct->jurisdiction($this->actor, $this->jurisdiction, 'other', 'Fix', 'same', $generation), 'TAX_KEY_CONFLICT'],
            [fn () => $correct->jurisdiction($this->actor, $this->jurisdiction, 'x', 'Fix', 'fresh', $generation), 'TAX_GENERATION_STALE'],
            [fn () => $correct->jurisdiction($this->actor, $this->jurisdiction, 'right-place', 'Again', 'again', $this->generation()), 'TAX_SOURCE_UNCHANGED'],
            [fn () => $correct->jurisdiction($this->actor, $this->jurisdiction, '  ', 'Fix', 'blank', $this->generation()), 'TAX_SOURCE_INVALID'],
            [fn () => $correct->jurisdiction($this->actor, $this->jurisdiction, 'x', '  ', 'noreason', $this->generation()), 'TAX_SOURCE_INVALID'],
            [fn () => $correct->item($this->actor, $this->classification, TaxNature::Both, 'x', 'Fix', 'both', $this->generation()), 'TAX_SOURCE_INVALID'],
            [fn () => $correct->jurisdiction($this->actor, 999999, 'x', 'Fix', 'missing', $this->generation()), 'TAX_SOURCE_INVALID'],
        ] as [$attempt, $code]) {
            try {
                $attempt();
                $this->fail("Expected {$code}.");
            } catch (TaxRefusal $refusal) {
                $this->assertSame($code, $refusal->reasonCode);
            }
        }
        $this->assertSame(1, DB::table('tax_quotation_source_corrections')->count());
    }

    public function test_it_is_denied_for_an_ungranted_actor_and_while_the_duty_is_staged(): void
    {
        $correct = app(CorrectQuotationTaxSource::class);
        $stranger = User::factory()->create();
        try {
            $correct->jurisdiction($stranger, $this->jurisdiction, 'x', 'Fix', 'k', $this->generation());
            $this->fail('An ungranted actor must be refused.');
        } catch (AuthorizationException) {
        }
        $this->app->instance(TaxDutyManifest::class, new TaxDutyManifest);
        try {
            $correct->jurisdiction($this->actor, $this->jurisdiction, 'x', 'Fix', 'k2', $this->generation());
            $this->fail('A staged duty must refuse even a granted actor.');
        } catch (AuthorizationException) {
        }
        $this->assertSame(0, DB::table('tax_quotation_source_corrections')->count());
    }

    public function test_another_company_cannot_correct_or_see_a_fact_and_the_evidence_is_append_only(): void
    {
        $company = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $id = app(CorrectQuotationTaxSource::class)->jurisdiction($this->actor, $this->jurisdiction, 'right-place', 'Fix', 'c1', $this->generation());
        foreach (['UPDATE tax_quotation_source_corrections SET reason = \'x\'', 'DELETE FROM tax_quotation_source_corrections', 'TRUNCATE tax_quotation_source_corrections'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("{$sql} must be refused.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        // A correction cannot name a fact of another company (composite foreign key) nor both kinds of fact.
        $this->expectException(QueryException::class);
        DB::table('tax_quotation_source_corrections')->insert(['company_id' => $company, 'jurisdiction_fact_id' => $this->jurisdiction, 'version_no' => 1, 'jurisdiction' => 'x', 'reason' => 'r', 'created_by' => $this->actor->id]);
        $this->assertNotNull($id);
    }
}
