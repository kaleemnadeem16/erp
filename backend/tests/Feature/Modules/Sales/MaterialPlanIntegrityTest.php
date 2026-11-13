<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Estimating\Actions\ActivateAssemblyVersion;
use App\Modules\Estimating\Actions\ActivateFormulationVersion;
use App\Modules\Estimating\Models\ApplicationRate;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Support\FormulationCycleGuard;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\AdoptQuotationMaterialPlan;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\RevalidateQuotationMaterialPlan;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationMaterialPlan;
use App\Modules\Sales\Models\QuotationMaterialPlanItem;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\MaterialPlan\MaterialPlanStanding;
use App\Support\UomDimension;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * Cross-phase integration, Batch 2 corrections — customer-specific material resolution is bound to the quotation's own recipient (never chosen by the seller), and the exact technical
 * sources an adopted plan was resolved from (the application-rate row with its quantity, every formulation version) are pinned with it, so a later source change can never
 * silently change what an adopted or issued plan means.
 *
 * Fixture: 10 sqm of an Assembly whose paint comes from application rate 0.1 L/sqm and the item's formulation. The company default formulation needs 1 kg pigment per litre and
 * Customer A's own formulation 2 kg, so the pigment standard is 1 kg for a prospect and 2 kg for Customer A.
 */
class MaterialPlanIntegrityTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private Uom $litre;

    private Uom $kg;

    private Uom $sqm;

    private Item $paint;

    private Item $pigment;

    private ApplicationRate $rate;

    private Formulation $defaultFormulation;

    private Formulation $customerFormulation;

    private AssemblyVersion $version;

    private Party $prospect;

    private CustomerAccount $accountA;

    private CustomerAccount $accountC;

    private int $revision;

    private int $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        Permission::findOrCreate('estimating.material_preview.run', 'web');
        $this->maker->givePermissionTo('estimating.material_preview.run');
        $this->checker->givePermissionTo('estimating.material_preview.run');
        $this->litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '1', 'is_base' => true]);
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->sqm = Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->paint = Item::create(['code' => 'PAINT', 'name' => 'Paint', 'type' => ItemType::SemiFinished, 'stock_uom_id' => $this->litre->id, 'category_id' => $category->id]);
        $this->pigment = Item::create(['code' => 'PIGMENT', 'name' => 'Pigment', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $category->id]);

        $receivable = ChartOfAccount::create(['code' => '1200', 'name' => 'Receivables', 'type' => AccountType::Asset]);
        $this->accountA = CustomerAccount::create(['party_id' => $this->customer->id, 'code' => 'CUST-A', 'payment_terms_days' => 30, 'receivable_account_id' => $receivable->id]);
        $this->prospect = Party::create(['code' => 'PROSPECT', 'name' => 'Prospect Ltd']);
        $other = Party::create(['code' => 'CUSTOMER-C', 'name' => 'Customer C']);
        $this->accountC = CustomerAccount::create(['party_id' => $other->id, 'code' => 'CUST-C', 'payment_terms_days' => 30, 'receivable_account_id' => $receivable->id]);

        $this->defaultFormulation = $this->formulation('FORM-DEFAULT', null, '1.000000');
        $this->customerFormulation = $this->formulation('FORM-A', $this->accountA->id, '2.000000');
        $this->rate = ApplicationRate::create(['item_id' => $this->paint->id, 'work_uom_id' => $this->sqm->id, 'qty_per_work_unit' => '0.100000', 'uom_id' => $this->litre->id, 'is_default' => true]);
        $assembly = Assembly::create(['code' => 'PAINT-WALL', 'name' => 'Paint wall', 'work_uom_id' => $this->sqm->id, 'margin_type' => 'MARKUP', 'margin_pct' => '0']);
        $version = $assembly->versions()->create(['version_no' => 1])->refresh();
        $version->components()->create(['kind' => 'MATERIAL', 'application_rate_id' => $this->rate->id, 'formulation_id' => $this->defaultFormulation->id, 'qty_per_work_unit' => '1.000000', 'uom_id' => $this->litre->id, 'sequence' => 0]);
        (new ActivateAssemblyVersion)($version, PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']));
        $this->version = $version->refresh();

        $created = app(CreateQuotationDraft::class)($this->maker, $this->issueKey(), ['recipient_party_id' => $this->customer->id, 'pricing_date' => $this->today(), 'tax_estimate_date' => $this->today(),
            'valid_through_local_date' => now()->addMonth()->format('Y-m-d')]);
        $this->revision = $created->revisionId;
        app(UpdateQuotationDraft::class)($this->maker, $this->revision, $created->inputGeneration, $this->issueKey(), ['add_lines' => [
            ['source_kind' => 'ASSEMBLY_WORK', 'assembly_version_id' => $this->version->id, 'offered_quantity' => '10', 'unit_price' => '100.0000'],
        ]]);
        $this->line = (int) QuotationDraftLine::query()->where('revision_id', $this->revision)->value('id');
    }

    private function formulation(string $code, ?int $customerId, string $pigmentQty): Formulation
    {
        $formulation = Formulation::create(['output_item_id' => $this->paint->id, 'code' => $code, 'name' => $code, 'output_qty' => '1.000000', 'output_uom_id' => $this->litre->id, 'customer_id' => $customerId]);
        $this->activateVersion($formulation, 1, $pigmentQty);

        return $formulation->refresh();
    }

    private function activateVersion(Formulation $formulation, int $number, string $pigmentQty): void
    {
        $version = $formulation->versions()->create(['version_no' => $number])->refresh();
        $version->lines()->create(['input_item_id' => $this->pigment->id, 'qty' => $pigmentQty, 'uom_id' => $this->kg->id, 'sequence' => 0]);
        (new ActivateFormulationVersion(new FormulationCycleGuard))($version, PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']));
    }

    private function generation(): int
    {
        return QuotationRevision::findOrFail($this->revision)->input_generation;
    }

    /** @param array<string, mixed> $plan */
    private function adopt(array $plan = []): array
    {
        return app(AdoptQuotationMaterialPlan::class)($this->maker, $this->revision, $this->line, $this->generation(), $this->issueKey(), $plan);
    }

    private function recipient(Party $party): void
    {
        app(UpdateQuotationDraft::class)($this->maker, $this->revision, $this->generation(), $this->issueKey(), ['recipient_party_id' => $party->id]);
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $refusal) {
            return (string) $refusal->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    /** @return list<string> */
    private function codes(?User $by = null): array
    {
        return array_values(array_filter(array_map(fn (array $b): string => $b['code'], app(AssessQuotationIssueReadiness::class)($by ?? $this->maker, $this->revision)['blockers']), fn (string $c): bool => str_starts_with($c, 'MATERIAL_PLAN')));
    }

    private function pigmentStandard(int $planId): string
    {
        return (string) QuotationMaterialPlanItem::query()->where('plan_id', $planId)->where('item_id', $this->pigment->id)->value('standard_quantity');
    }

    public function test_the_customer_is_the_recipients_own_account_and_a_prospect_resolves_the_company_default(): void
    {
        $forA = $this->adopt();
        $plan = QuotationMaterialPlan::findOrFail($forA['plan_id']);
        $this->assertSame([$this->customer->id, $this->accountA->id], [$plan->recipient_party_id, $plan->customer_id]);
        $this->assertSame('2.000000000', $this->pigmentStandard($plan->id), "Customer A's own formulation: 10 sqm x 0.1 L x 2 kg");

        $this->recipient($this->prospect);
        $forProspect = $this->adopt();
        $prospectPlan = QuotationMaterialPlan::findOrFail($forProspect['plan_id']);
        $this->assertSame([$this->prospect->id, null], [$prospectPlan->recipient_party_id, $prospectPlan->customer_id]);
        $this->assertSame('1.000000000', $this->pigmentStandard($prospectPlan->id), 'a prospect has no customer account, so the company default applies');
    }

    public function test_a_mismatched_customer_identity_is_refused_whoever_is_named(): void
    {
        $this->assertSame('MATERIAL_PLAN_CUSTOMER_MISMATCH', $this->refusal(fn () => $this->adopt(['customer_id' => $this->accountC->id])), "another customer's account");
        $this->assertSame(0, QuotationMaterialPlan::query()->count());
        $this->assertSame('AUDIT_ONLY', $this->adopt(['customer_id' => $this->accountA->id])['effect'], "naming the recipient's own account is accepted");

        $this->recipient($this->prospect);
        $this->assertSame('MATERIAL_PLAN_CUSTOMER_MISMATCH', $this->refusal(fn () => $this->adopt(['customer_id' => $this->accountA->id])), 'a prospect cannot borrow a customer-specific formulation');
    }

    public function test_changing_the_recipient_makes_the_plan_stale_and_revalidation_says_why(): void
    {
        $adopted = $this->adopt();
        $this->assertSame(MaterialPlanStanding::READY, app(MaterialPlanStanding::class)->forLine(QuotationDraftLine::findOrFail($this->line))['state']);
        $this->assertSame([], $this->codes());

        $this->recipient($this->prospect);

        $this->assertSame(MaterialPlanStanding::STALE, app(MaterialPlanStanding::class)->forLine(QuotationDraftLine::findOrFail($this->line))['state']);
        $this->assertSame(['MATERIAL_PLAN_STALE'], $this->codes());
        $this->assertSame(['CHANGED', ['RECIPIENT_CHANGED']], array_values(app(RevalidateQuotationMaterialPlan::class)($this->maker, $this->revision, $this->line)));
        $this->assertSame($adopted['plan_id'], QuotationMaterialPlan::query()->sole()->id, 'the adoption itself is untouched');
    }

    public function test_the_database_binds_a_plan_to_its_recipient_and_that_recipients_customer(): void
    {
        $this->adopt();
        $this->recipient($this->prospect);
        $plan = QuotationMaterialPlan::query()->sole();
        $row = collect($plan->getAttributes())->except(['id'])->all();
        $row['inputs'] = json_encode($plan->inputs);
        $row['provenance'] = json_encode($plan->provenance);
        $row['supersedes_id'] = $plan->id;

        foreach (['the previous recipient' => ['recipient_party_id' => $this->customer->id], 'a customer that is not the recipient\'s' => ['recipient_party_id' => $this->prospect->id, 'customer_id' => $this->accountC->id]] as $label => $wrong) {
            try {
                DB::transaction(fn () => DB::table('quotation_material_plans')->insert($wrong + $row));
                $this->fail("the database accepted a plan for {$label}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_exact_technical_sources_are_pinned_with_the_adopted_plan(): void
    {
        $plan = QuotationMaterialPlan::findOrFail($this->adopt()['plan_id']);
        $component = $plan->provenance['components'][0];

        $this->assertSame($this->version->id, $plan->provenance['assembly_version_id']);
        $this->assertSame([$this->rate->id, '0.100000', $this->litre->id], [$component['rate']['resolved_id'], $component['rate']['qty_per_work_unit'], $component['rate']['uom_id']]);
        $customerVersion = $this->customerFormulation->activeVersion()->firstOrFail();
        $this->assertEquals([['formulation_id' => $this->customerFormulation->id, 'version_id' => $customerVersion->id, 'version_no' => 1, 'customer_id' => $this->accountA->id]], $component['formulations']);
    }

    public function test_a_source_change_cannot_silently_change_what_the_plan_means(): void
    {
        $first = QuotationMaterialPlan::findOrFail($this->adopt()['plan_id']);
        $frozen = [$first->provenance, $this->pigmentStandard($first->id)];
        $this->assertSame([], $this->codes());

        // A new ACTIVE formulation version with exactly the same quantities: nothing a quantity comparison could see, but the version the plan names is no longer the one in force.
        $this->activateVersion($this->customerFormulation, 2, '2.000000');
        $this->assertSame(['MATERIAL_PLAN_STALE'], $this->codes(), 'same quantities, different formulation version');
        $this->assertSame(['CHANGED', ['ROUTING_FACTS_CHANGED']], array_values(app(RevalidateQuotationMaterialPlan::class)($this->maker, $this->revision, $this->line)));

        $second = QuotationMaterialPlan::findOrFail($this->adopt()['plan_id']);
        $this->assertSame([], $this->codes());
        $this->assertNotEquals($first->provenance, $second->provenance);

        // The application rate row changes in place: the plan names the quantity it carried.
        $this->rate->update(['qty_per_work_unit' => '0.150000']);
        $this->assertSame(['MATERIAL_PLAN_STALE'], $this->codes());
        $this->rate->update(['qty_per_work_unit' => '0.100000']);
        $this->assertSame([], $this->codes(), 'put back, the adopted plan describes the sources again');

        // Nothing already adopted was rewritten by any of this.
        $this->assertEquals($frozen, [QuotationMaterialPlan::findOrFail($first->id)->provenance, $this->pigmentStandard($first->id)]);
    }

    public function test_an_issued_plan_keeps_its_pinned_sources_after_the_sources_move_on(): void
    {
        $plan = QuotationMaterialPlan::findOrFail($this->adopt()['plan_id']);
        app(IssueQuotation::class)($this->checker, $this->revision, $this->generation(), $this->issueKey());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->activateVersion($this->customerFormulation, 2, '3.000000');
        $this->rate->update(['qty_per_work_unit' => '0.500000']);

        $issued = QuotationIssuedLine::query()->where('revision_id', $this->revision)->sole();
        $kept = QuotationMaterialPlan::findOrFail($issued->material_plan_id);
        $this->assertSame($plan->id, $kept->id);
        $this->assertEquals($plan->provenance, $kept->provenance);
        $this->assertSame('2.000000000', $this->pigmentStandard($kept->id), 'what was sold still reads as what was resolved when it was sold');
    }
}
