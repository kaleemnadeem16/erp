<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\ActivateAssemblyVersion;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\PublishQuotationControlSet;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Issue\ConfiguredQuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\Fr3QuotationTaxProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxProvider;
use App\Modules\Tax\Actions\DeclareQuotationTaxSource;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxNature;
use App\Support\UomDimension;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Feature\Modules\Tax\Concerns\BuildsQuotationTax;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * Cross-phase integration, Batch 1 — what makes an Assembly quotation line issuable: Tax classifies the Assembly's offered work by its own explicit fact (ADR 0056), and the company's
 * margin controls are judged through the cost-blind evaluator, so a seller without the estimating cost duty is neither blocked by it nor shown the figures. Cost per unit is
 * 0.5 kg x 10.0000 + 777.7777 = 782.7777; three units sell at 4500.0000, a margin of about 47.9%.
 */
class AssemblyQuotationIssueEvidenceTest extends TestCase
{
    use BuildsIssuableQuotations;
    use BuildsQuotationTax;
    use RefreshDatabase;

    private const COST_FIGURES = ['782.7777', '2348.3331', '2151.6669', '47.8', '47.9'];

    private Uom $sqm;

    private AssemblyVersion $version;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        $this->setUpQuotationTax();
        $this->currency = (int) Currency::query()->where('code', 'PKR')->value('id');
        $this->activateDeclaration($this->declaration([$this->coverage('services', '10.000000000', 'TAXABLE', false, 'SERVICES')]));
        app(DeclareQuotationTaxSource::class)->jurisdiction($this->actor, 'illustrative', new EffectivePeriod('2026-01-01', '2028-01-01'), 'Illustrative place', 'j', $this->generation());
        $this->app->forgetInstance(QuotationTaxProvider::class);
        $this->app->bind(QuotationTaxProvider::class, Fr3QuotationTaxProvider::class);
        $this->app->forgetInstance(QuotationControlsProvider::class);
        $this->app->bind(QuotationControlsProvider::class, ConfiguredQuotationControlsProvider::class);

        Permission::findOrCreate(PermissionVocabulary::FINANCIAL_CONFIGURE, 'web');
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(PermissionVocabulary::FINANCIAL_CONFIGURE);
        $names = [PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_ISSUE, PermissionVocabulary::FINANCIAL_CONFIGURE];
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest(array_map(fn (SalesDuty $d): SalesDuty => in_array($d->name, $names, true)
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate) : $d, SalesDutyManifest::shipped()->all())));

        $kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->sqm = Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $pigment = Item::create(['code' => 'pigment', 'name' => 'Pigment', 'type' => ItemType::Raw, 'stock_uom_id' => $kg->id, 'category_id' => $category->id]);
        AvcoPool::create(['item_id' => $pigment->id, 'scope' => AvcoPoolScope::CompanyItem, 'qty_on_hand' => '100', 'inventory_value' => '1000', 'avg_unit_cost' => '10.0000',
            'base_avg_unit_cost' => '10.0000', 'last_valid_avg_unit_cost' => '10.0000', 'status' => AvcoPoolStatus::Active]);
        $assembly = Assembly::create(['code' => 'ASM-1', 'name' => 'Epoxy floor', 'work_uom_id' => $this->sqm->id, 'margin_type' => 'MARKUP', 'margin_pct' => '20']);
        $version = $assembly->versions()->create(['version_no' => 1])->refresh();
        $version->components()->create(['kind' => 'MATERIAL', 'item_id' => $pigment->id, 'qty_per_work_unit' => '0.500000', 'uom_id' => $kg->id, 'sequence' => 0]);
        $version->components()->create(['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '777.7777', 'sequence' => 1]);
        (new ActivateAssemblyVersion)($version, PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']));
        $this->version = $version->refresh();
    }

    private function draftWith(array ...$lines): QuotationRevision
    {
        $created = app(CreateQuotationDraft::class)($this->maker, $this->issueKey(), ['recipient_party_id' => $this->customer->id, 'pricing_date' => $this->today(), 'tax_estimate_date' => $this->today(),
            'valid_through_local_date' => now()->addMonth()->format('Y-m-d')]);
        app(UpdateQuotationDraft::class)($this->maker, $created->revisionId, $created->inputGeneration, $this->issueKey(), ['add_lines' => $lines]);

        return QuotationRevision::findOrFail($created->revisionId);
    }

    /** @return array<string, mixed> */
    private function assemblyLine(string $price = '1500.0000'): array
    {
        return ['source_kind' => 'ASSEMBLY_WORK', 'assembly_version_id' => $this->version->id, 'offered_quantity' => '3', 'unit_price' => $price];
    }

    /** @return list<string> */
    private function codes(QuotationRevision $revision): array
    {
        return array_map(fn (array $b): string => $b['code'].($b['line_id'] === null ? '' : '@'.$b['line_id']), app(AssessQuotationIssueReadiness::class)($this->maker, $revision->id)['blockers']);
    }

    private function publish(string $kind, string $limit, string $mode = 'MANDATORY'): void
    {
        $control = ['ref' => 'ctl-'.strtolower($kind), 'kind' => $kind, 'mode' => $mode, 'limit' => $limit] + (str_ends_with($kind, 'PERCENT') ? [] : ['currency_id' => (int) Currency::query()->where('code', 'PKR')->value('id')]);
        app(PublishQuotationControlSet::class)($this->admin, [$control], 'Margin review');
    }

    public function test_tax_classifies_an_assembly_line_by_its_own_explicit_fact_and_never_infers_one(): void
    {
        $revision = $this->draftWith($this->assemblyLine());
        Sanctum::actingAs($this->maker);
        $reasons = fn (): array => $this->getJson("/api/v1/quotations/{$revision->id}/preview")->assertOk()->json('data.tax.reasons');

        $this->assertContains('TAX_CLASSIFICATION_REQUIRED', $reasons(), 'no Assembly fact: blocked, with no default invented from the components or the company');

        app(DeclareQuotationTaxSource::class)->assembly($this->actor, $this->version->assembly_id, TaxNature::Services, 'services', new EffectivePeriod('2026-01-01', '2028-01-01'), 'Offered work is a service', 'a', $this->generation());

        $tax = $this->getJson("/api/v1/quotations/{$revision->id}/preview")->assertOk()->json('data.tax');
        $this->assertSame(['COMPLETE', '450.0000', '4950.0000'], [$tax['certainty'], $tax['total'], $tax['total_including_tax']['amount']], '10% of the 4500.0000 net');
    }

    public function test_the_assembly_tax_fact_is_company_scoped_period_exclusive_and_append_only(): void
    {
        $declare = fn (string $from, ?string $to, string $key) => app(DeclareQuotationTaxSource::class)->assembly($this->actor, $this->version->assembly_id, TaxNature::Services, 'services', new EffectivePeriod($from, $to), 'Reason', $key, $this->generation());
        $id = $declare('2026-01-01', '2027-01-01', 'a1');

        try {
            $declare('2026-06-01', '2028-01-01', 'a2');
            $this->fail('two Assembly facts must not overlap in time');
        } catch (\Throwable $overlap) {
            $this->assertNotSame('', $overlap->getMessage());
        }
        $this->assertGreaterThan(0, $id);
        $this->expectException(QueryException::class);
        DB::table('tax_quotation_assembly_classifications')->where('id', $id)->update(['classification' => 'other']);
    }

    public function test_margin_controls_on_an_assembly_line_are_judged_cost_blind_and_a_failure_blocks_issue(): void
    {
        $revision = $this->draftWith($this->assemblyLine());
        $controlBlockers = fn (): array => array_values(array_filter($this->codes($revision), fn (string $c): bool => str_starts_with($c, 'CONTROL')));

        $this->publish('MIN_MARGIN_PERCENT', '30');
        $this->assertSame([], $controlBlockers(), 'about 47.9% clears a 30% floor');

        $this->publish('MIN_MARGIN_PERCENT', '60');
        $line = (int) $revision->lines()->value('id');
        $this->assertSame(['CONTROL_FAILED@'.$line], $controlBlockers());

        $this->publish('MIN_MARGIN_AMOUNT', '2000');
        $this->assertSame([], $controlBlockers(), 'a margin of about 2151.67 clears an absolute 2000');
        $this->publish('MIN_MARGIN_AMOUNT', '2200');
        $this->assertSame(['CONTROL_FAILED@'.$line], $controlBlockers());

        $everything = json_encode(app(AssessQuotationIssueReadiness::class)($this->maker, $revision->id));
        foreach (self::COST_FIGURES as $secret) {
            $this->assertStringNotContainsString($secret, $everything, "figure {$secret} must not reach the seller");
        }
    }

    public function test_a_manual_line_still_cannot_prove_a_margin_so_the_same_control_stays_unevaluable(): void
    {
        $revision = $this->draftWith(['source_kind' => 'MANUAL', 'description' => 'Survey', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '500.0000']);
        $this->publish('MIN_MARGIN_PERCENT', '30');

        $this->assertContains('CONTROL_UNEVALUABLE@'.$revision->lines()->value('id'), $this->codes($revision), 'no cost evidence exists for a manual line; unknown is never a pass');
    }

    public function test_a_floor_control_judges_an_assembly_line_without_any_cost(): void
    {
        $revision = $this->draftWith($this->assemblyLine('100.0000'));
        $this->publish('FLOOR', '1000');

        $this->assertSame(['CONTROL_FAILED@'.$revision->lines()->value('id')], array_values(array_filter($this->codes($revision), fn (string $c): bool => str_starts_with($c, 'CONTROL'))));
    }
}
