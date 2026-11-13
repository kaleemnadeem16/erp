<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Approvals\Actions\DecideApproval;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Estimating\Actions\ActivateAssemblyVersion;
use App\Modules\Estimating\Actions\CreateMaterialVariancePolicy;
use App\Modules\Estimating\Actions\SetStockMixPolicy;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Estimating\Models\MaterialVarianceReason;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\StockMixLevel;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Sales\Actions\AdoptQuotationMaterialPlan;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\RequestQuotationMaterialPlanApproval;
use App\Modules\Sales\Actions\RevalidateQuotationMaterialPlan;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationMaterialPlan;
use App\Modules\Sales\Models\QuotationMaterialPlanApproval;
use App\Modules\Sales\Models\QuotationMaterialPlanItem;
use App\Modules\Sales\Models\QuotationMaterialPlanRevalidation;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\MaterialPlan\MaterialPlanStanding;
use App\Support\UomDimension;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Support\PermissionedActor;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/**
 * Cross-phase integration, Batch 2 — a quotation Assembly line adopts its Material Plan (ADR 0190): the Phase 3 resolution of the pinned Assembly version, the seller's planned
 * quantities and hand-chosen stock, the variance (ADR 0054) and stock-choice (ADR 0076) controls, the approvals they need, deliberate revalidation and the evidence frozen at issue.
 *
 * Fixture: 5 sqm of an Assembly needing 2 kg of resin per sqm, so the standard is 10 kg. Resin is held in two stores — `a` (10 kg at 2.0000) and `b` (10 kg at 3.0000) — so the FIFO
 * proposal draws `a` and choosing `b` is 50% dearer per kg. A 10 -> 15 kg plan is +50%; the company's 20% band is what it crosses.
 */
class MaterialPlanAdoptionTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private const ESTIMATING = ['estimating.material_preview.run', 'estimating.inventory_availability.view', 'estimating.material_plan.adjust', 'estimating.package_plan.adopt',
        'estimating.stock_mix.choose', 'estimating.material_variance.request'];

    private Uom $kg;

    private Uom $sqm;

    private Item $resin;

    private AssemblyVersion $version;

    private User $admin;

    /** @var array<string, Location> */
    private array $stores = [];

    private int $movements = 0;

    private int $line;

    private int $revision;

    private MaterialVarianceReason $porous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        foreach (self::ESTIMATING as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->maker->givePermissionTo(self::ESTIMATING);
        // Issuing re-evaluates an adopted plan as the issuer, so the issuer needs the preview rights (not the right to change a plan).
        $this->checker->givePermissionTo(['estimating.material_preview.run', 'estimating.inventory_availability.view']);
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->sqm = Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'FIFO']);
        $this->resin = Item::create(['code' => 'RESIN', 'name' => 'Resin', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $category->id, 'is_stocked' => true,
            'inventory_account_id' => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset])->id]);
        $this->receive('a', '10', '2.0000');
        $this->receive('b', '10', '3.0000');
        $assembly = Assembly::create(['code' => 'ASM-1', 'name' => 'Resin coat', 'work_uom_id' => $this->sqm->id, 'margin_type' => 'MARKUP', 'margin_pct' => '20']);
        $version = $assembly->versions()->create(['version_no' => 1])->refresh();
        $version->components()->create(['kind' => 'MATERIAL', 'item_id' => $this->resin->id, 'qty_per_work_unit' => '2.000000', 'uom_id' => $this->kg->id, 'sequence' => 0]);
        (new ActivateAssemblyVersion)($version, PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']));
        $this->version = $version->refresh();
        $this->porous = MaterialVarianceReason::create(['code' => 'POROUS', 'name' => 'Porous surface', 'active' => true]);
        $this->admin = PermissionedActor::grant(User::factory()->create(), ['estimating.material_variance_policy.manage', 'estimating.stock_mix_policy.manage', 'approvals.settings.manage']);

        $created = app(CreateQuotationDraft::class)($this->maker, $this->issueKey(), ['recipient_party_id' => $this->customer->id, 'pricing_date' => $this->today(), 'tax_estimate_date' => $this->today(),
            'valid_through_local_date' => now()->addMonth()->format('Y-m-d')]);
        $this->revision = $created->revisionId;
        app(UpdateQuotationDraft::class)($this->maker, $this->revision, $created->inputGeneration, $this->issueKey(), ['add_lines' => [
            ['source_kind' => 'ASSEMBLY_WORK', 'assembly_version_id' => $this->version->id, 'offered_quantity' => '5', 'unit_price' => '1000.0000'],
        ]]);
        $this->line = (int) QuotationDraftLine::query()->where('revision_id', $this->revision)->value('id');
    }

    private function receive(string $warehouse, string $qty, string $unitCost): void
    {
        $this->stores[$warehouse] ??= app(CreateWarehouse::class)(['code' => $warehouse, 'name' => strtoupper($warehouse), 'location_code' => 'main', 'location_name' => 'Main'], PurchasingActor::make(['inventory.warehouse.manage']))->locations()->sole();
        $pkr = Currency::query()->where('code', 'PKR')->firstOrFail();
        app(PostInventoryMovement::class)([
            'item_id' => $this->resin->id, 'location_id' => $this->stores[$warehouse]->id, 'qty' => $qty, 'uom_id' => $this->kg->id, 'unit_cost' => $unitCost, 'base_unit_cost' => $unitCost,
            'currency_id' => $pkr->id, 'fx_rate' => '1', 'source_type' => 'TEST_RECEIPT', 'source_id' => ++$this->movements, 'moved_at' => now(),
        ]);
    }

    private function generation(): int
    {
        return QuotationRevision::findOrFail($this->revision)->input_generation;
    }

    /** @param array<string, mixed> $plan */
    private function adopt(array $plan = [], ?User $by = null, ?string $key = null, ?int $generation = null): array
    {
        return app(AdoptQuotationMaterialPlan::class)($by ?? $this->maker, $this->revision, $this->line, $generation ?? $this->generation(), $key ?? $this->issueKey(), $plan);
    }

    /** @return array<string, mixed> */
    private function adjust(string $quantity, ?int $reason = null): array
    {
        return ['planned_adjustments' => [['item_id' => $this->resin->id, 'planned_quantity' => $quantity, 'uom_id' => $this->kg->id, 'reason_id' => $reason]]];
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

    private int $policies = 0;

    private function varianceBand(string $effect, string $threshold = '20'): void
    {
        $from = now()->subDays(30 - ++$this->policies * 5)->toDateString();
        MaterialVariancePolicy::query()->where('status', 'ACTIVE')->whereNull('effective_to')->update(['status' => 'SUPERSEDED', 'effective_to' => $from]);
        app(CreateMaterialVariancePolicy::class)(['scope_type' => 'COMPANY', 'scope_id' => null, 'reason' => 'Test', 'bands' => [['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => $threshold, 'uom_id' => null, 'effect' => $effect]]], $this->admin);
        MaterialVariancePolicy::query()->where('status', 'DRAFT')->update(['status' => 'ACTIVE', 'effective_from' => $from]);
    }

    private function approvals(string $subject, ApprovalMode $mode = ApprovalMode::Policy, bool $withPolicy = true): void
    {
        ApprovalSubjectSetting::query()->updateOrCreate(['subject_type' => $subject], ['mode' => $mode]);
        if ($withPolicy) {
            $policy = ApprovalPolicy::query()->create(['subject_type' => $subject, 'code' => strtolower($subject), 'version_no' => 1, 'name' => 'Sign-off', 'priority' => 0, 'status' => ApprovalPolicyStatus::Active, 'reason' => 't', 'changed_by' => $this->admin->id]);
            $policy->steps()->create(['stage' => 1, 'name' => 'Estimating lead', 'required_approvals' => 1]);
        }
    }

    private function codes(): array
    {
        return array_values(array_filter(array_map(fn (array $b): string => $b['code'], app(AssessQuotationIssueReadiness::class)($this->maker, $this->revision)['blockers']), fn (string $c): bool => str_starts_with($c, 'MATERIAL_PLAN')));
    }

    private function standing(): string
    {
        return app(MaterialPlanStanding::class)->forLine(QuotationDraftLine::findOrFail($this->line))['state'];
    }

    public function test_a_standard_plan_is_adopted_with_its_leaf_materials_and_no_control(): void
    {
        $result = $this->adopt();

        $this->assertSame(['AUDIT_ONLY', 0, false], [$result['effect'], $result['approvals_needed'], $result['replayed']]);
        $plan = QuotationMaterialPlan::findOrFail($result['plan_id']);
        $this->assertSame([$this->version->id, '5.0000', $this->sqm->id, $this->maker->id, null], [$plan->assembly_version_id, (string) $plan->work_quantity, $plan->work_uom_id, $plan->adopted_by, $plan->supersedes_id]);
        $item = QuotationMaterialPlanItem::query()->where('plan_id', $plan->id)->sole();
        $this->assertSame([$this->resin->id, '10.000000000', '10.000000000', '0.000000000', null, null], [$item->item_id, (string) $item->standard_quantity, (string) $item->planned_quantity, (string) $item->delta_quantity, $item->effect, $item->mix]);
        $this->assertSame(MaterialPlanStanding::READY, $this->standing());
        $this->assertSame([], $this->codes());
    }

    public function test_a_planned_difference_needs_a_reason_and_keeps_standard_and_planned_apart(): void
    {
        // No policy configured: ADR 0054's built-in default, any non-zero difference needs a reason.
        $this->assertSame('MATERIAL_PLAN_REASON_REQUIRED', $this->refusal(fn () => $this->adopt($this->adjust('11'))));
        $this->assertSame(0, QuotationMaterialPlan::query()->count(), 'a refused plan stores nothing');

        $result = $this->adopt($this->adjust('11', $this->porous->id));

        $this->assertSame('REASON_REQUIRED', $result['effect']);
        $item = QuotationMaterialPlanItem::query()->sole();
        $this->assertSame(['10.000000000', '11.000000000', '1.000000000', '10.000000', 'REASON_REQUIRED', $this->porous->id], [(string) $item->standard_quantity, (string) $item->planned_quantity, (string) $item->delta_quantity, (string) $item->delta_percent, $item->effect, $item->reason_id]);
    }

    public function test_a_blocked_band_refuses_the_plan(): void
    {
        $this->varianceBand('BLOCKED', '30');

        $this->assertSame('MATERIAL_PLAN_BLOCKED', $this->refusal(fn () => $this->adopt($this->adjust('15', $this->porous->id))));
        $this->assertSame(0, QuotationMaterialPlan::query()->count());
    }

    public function test_adoption_needs_the_quotation_duty_the_estimating_rights_and_a_current_draft(): void
    {
        $stranger = User::factory()->create();
        $stranger->givePermissionTo(self::ESTIMATING);
        try {
            $this->adopt([], $stranger);
            $this->fail('a person with no quotation duty must be refused');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->maker->revokePermissionTo('estimating.material_plan.adjust');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        try {
            $this->adopt($this->adjust('11', $this->porous->id), User::query()->findOrFail($this->maker->id));
            $this->fail('changing a plan needs its own right');
        } catch (DomainInvariantException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('AUDIT_ONLY', $this->adopt([], User::query()->findOrFail($this->maker->id))['effect'], 'adopting the standard plan changes nothing, so it needs no adjustment right');
        $this->assertSame(1, QuotationMaterialPlan::query()->count());
    }

    public function test_a_stale_generation_a_non_assembly_line_and_a_replayed_key_are_handled(): void
    {
        $this->assertSame('QUOTATION_DRAFT_STALE', $this->refusal(fn () => $this->adopt([], null, null, $this->generation() - 1)));

        $first = $this->adopt([], null, 'same-key');
        $again = $this->adopt([], null, 'same-key');
        $this->assertSame([$first['plan_id'], true], [$again['plan_id'], $again['replayed']]);
        $this->assertSame(1, QuotationMaterialPlan::query()->count());

        app(UpdateQuotationDraft::class)($this->maker, $this->revision, $this->generation(), $this->issueKey(), ['add_lines' => [['source_kind' => 'MANUAL', 'description' => 'Survey', 'manual_measure' => 'job', 'offered_quantity' => '1']]]);
        $manual = (int) QuotationDraftLine::query()->where('source_kind', 'MANUAL')->value('id');
        $this->assertSame('MATERIAL_PLAN_NOT_APPLICABLE', $this->refusal(fn () => app(AdoptQuotationMaterialPlan::class)($this->maker, $this->revision, $manual, $this->generation(), $this->issueKey(), [])));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->adopt(['stock' => 1])), 'an unknown field is refused, never ignored');
    }

    public function test_a_variance_in_an_approval_band_needs_a_granted_approval_before_issue_and_the_figure_stays_internal(): void
    {
        $this->approvals('MATERIAL_VARIANCE');
        $this->varianceBand('APPROVAL_REQUIRED');

        $result = $this->adopt($this->adjust('15', $this->porous->id));

        $this->assertSame(['APPROVAL_REQUIRED', 1], [$result['effect'], $result['approvals_needed']]);
        $this->assertStringNotContainsString('15.0000', json_encode($result), 'the cost-blind adopter is not handed the internal cost');
        $row = QuotationMaterialPlanApproval::query()->sole();
        $this->assertSame('MATERIAL_VARIANCE', $row->subject_code);
        $this->assertSame([$this->resin->id, '15.0000'], [$row->facts['item_id'], $row->facts['amount_base']], '15 kg draws 10 at 2.0000 and 5 at 3.0000 (35.0000) against the 20.0000 standard: the routing amount, taken server-side');
        $this->assertSame(MaterialPlanStanding::APPROVAL_NOT_REQUESTED, $this->standing());
        $this->assertSame(['MATERIAL_PLAN_APPROVAL_REQUIRED'], $this->codes());

        $this->assertSame(1, app(RequestQuotationMaterialPlanApproval::class)($this->maker, $this->revision, $this->line));
        $this->assertSame(1, app(RequestQuotationMaterialPlanApproval::class)($this->maker, $this->revision, $this->line), 'asking again is the same open request');
        $this->assertSame(1, ApprovalRequest::query()->where('subject_type', 'MATERIAL_VARIANCE')->count());
        $this->assertSame(['MATERIAL_PLAN_APPROVAL_PENDING'], $this->codes());

        $approver = PermissionedActor::grant(User::factory()->create(), ['estimating.material_variance.approve']);
        $request = ApprovalRequest::query()->sole();
        app(DecideApproval::class)($request, $approver, ApprovalDecisionKind::Approved);

        $this->assertSame(ApprovalRequestStatus::Approved, $request->refresh()->status);
        $this->assertSame(MaterialPlanStanding::READY, $this->standing());
        $this->assertSame([], $this->codes());
    }

    public function test_a_requested_approval_is_never_read_as_approved_without_a_policy_and_a_switched_off_workflow_blocks(): void
    {
        $this->approvals('MATERIAL_VARIANCE', ApprovalMode::Policy, false);
        $this->varianceBand('APPROVAL_REQUIRED');
        $this->adopt($this->adjust('15', $this->porous->id));

        $this->assertSame('MATERIAL_PLAN_APPROVAL_POLICY_MISSING', $this->refusal(fn () => app(RequestQuotationMaterialPlanApproval::class)($this->maker, $this->revision, $this->line)));
        $this->assertSame(0, ApprovalRequest::query()->count());
        $this->assertSame(['MATERIAL_PLAN_APPROVAL_REQUIRED'], $this->codes());

        // Switched off after the band was stored: Phase 3 fails closed, so the plan cannot even be adopted.
        $this->approvals('MATERIAL_VARIANCE', ApprovalMode::None, false);
        $this->assertSame('MATERIAL_PLAN_BLOCKED', $this->refusal(fn () => $this->adopt($this->adjust('16', $this->porous->id))));
    }

    public function test_changing_the_line_after_approval_ends_the_approval_and_a_new_adoption_supersedes_the_old(): void
    {
        $this->approvals('MATERIAL_VARIANCE');
        $this->varianceBand('APPROVAL_REQUIRED');
        $first = $this->adopt($this->adjust('15', $this->porous->id));
        app(RequestQuotationMaterialPlanApproval::class)($this->maker, $this->revision, $this->line);
        $approver = PermissionedActor::grant(User::factory()->create(), ['estimating.material_variance.approve']);
        app(DecideApproval::class)(ApprovalRequest::query()->sole(), $approver, ApprovalDecisionKind::Approved);
        $this->assertSame(MaterialPlanStanding::READY, $this->standing());

        // The quantity moves: the plan no longer describes the line and its approval is ended (history stays).
        app(UpdateQuotationDraft::class)($this->maker, $this->revision, $this->generation(), $this->issueKey(), ['update_lines' => [['id' => $this->line, 'offered_quantity' => '6']]]);

        $this->assertSame(MaterialPlanStanding::STALE, $this->standing());
        $this->assertSame(['MATERIAL_PLAN_STALE'], $this->codes());
        $this->assertSame(ApprovalRequestStatus::Invalidated, ApprovalRequest::query()->sole()->status);
        $this->assertSame('MATERIAL_PLAN_STALE', $this->refusal(fn () => app(RequestQuotationMaterialPlanApproval::class)($this->maker, $this->revision, $this->line)));

        $second = $this->adopt($this->adjust('18', $this->porous->id));
        $this->assertNotSame($first['plan_id'], $second['plan_id']);
        $this->assertSame($first['plan_id'], QuotationMaterialPlan::findOrFail($second['plan_id'])->supersedes_id);
        $this->assertSame(2, QuotationMaterialPlan::query()->count(), 'the earlier adoption stays as history');
        $this->assertSame(['MATERIAL_PLAN_APPROVAL_REQUIRED'], $this->codes(), 'an old approval never floats onto the new plan');
    }

    public function test_revalidation_appends_evidence_and_a_changed_policy_ends_the_approval(): void
    {
        $this->approvals('MATERIAL_VARIANCE');
        $this->varianceBand('APPROVAL_REQUIRED');
        $this->adopt($this->adjust('15', $this->porous->id));
        app(RequestQuotationMaterialPlanApproval::class)($this->maker, $this->revision, $this->line);

        $same = app(RevalidateQuotationMaterialPlan::class)($this->maker, $this->revision, $this->line);
        $this->assertSame(['CURRENT', []], [$same['outcome'], $same['change_codes']]);
        $this->assertSame(ApprovalRequestStatus::Pending, ApprovalRequest::query()->sole()->status, 'a current re-check leaves the request as it was');

        $this->varianceBand('BLOCKED', '30');
        $changed = app(RevalidateQuotationMaterialPlan::class)($this->maker, $this->revision, $this->line);

        $this->assertSame(['CHANGED', ['MATERIAL_PLAN_BLOCKED']], [$changed['outcome'], $changed['change_codes']]);
        $this->assertSame(['CURRENT', 'CHANGED'], QuotationMaterialPlanRevalidation::query()->orderBy('id')->pluck('outcome')->all());
        $this->assertSame(ApprovalRequestStatus::Invalidated, ApprovalRequest::query()->sole()->status);
        $this->assertSame(MaterialPlanStanding::STALE, $this->standing());
        $this->expectException(QueryException::class);
        DB::table('quotation_material_plan_revalidations')->update(['outcome' => 'CURRENT']);
    }

    public function test_the_issue_assessment_finds_a_plan_the_world_has_moved_from_without_a_deliberate_recheck(): void
    {
        $this->varianceBand('AUDIT_ONLY', '5');
        $this->adopt($this->adjust('11'));
        $this->assertSame([], $this->codes());

        $this->varianceBand('REASON_REQUIRED', '5');

        $this->assertSame(['MATERIAL_PLAN_STALE'], $this->codes(), 'the effect behind the adoption changed, so issuing on it would be issuing on a fact no longer true');
    }

    public function test_a_hand_chosen_stock_mix_over_its_limit_needs_its_reason_or_its_approval(): void
    {
        $this->actingAsAdminLevel(StockMixLevel::ReasonRequired);
        $mix = [['item_id' => $this->resin->id, 'location_id' => $this->stores['b']->id, 'batch_id' => null, 'quantity' => '10', 'uom_id' => $this->kg->id]];

        $this->assertSame('STOCK_CHOICE_NOT_ADOPTABLE', $this->refusal(fn () => $this->adopt(['stock_mix' => $mix])), '50% dearer than the proposal and no reason given');
        $this->assertSame('STOCK_CHOICE_NOT_ADOPTABLE', $this->refusal(fn () => $this->adopt(['stock_mix' => [['quantity' => '4'] + $mix[0]], 'stock_mix_notes' => [['item_id' => $this->resin->id, 'note' => 'Near the site']]])), 'a mix that does not cover the plan is not a plan');

        $given = $this->adopt(['stock_mix' => $mix, 'stock_mix_notes' => [['item_id' => $this->resin->id, 'note' => 'Store b is next to the site']]]);
        $this->assertSame('AUDIT_ONLY', $given['effect']);
        $item = QuotationMaterialPlanItem::query()->sole();
        $this->assertSame(['REASON_REQUIRED', 'REASON_PROVIDED', 'Store b is next to the site'], [$item->mix_level, $item->mix_status, $item->mix_note]);
        $this->assertEquals([['warehouse_id' => $this->stores['b']->warehouse_id, 'location_id' => $this->stores['b']->id, 'batch_id' => null, 'quantity' => '10.000000000']], $item->mix);

        $this->approvals('STOCK_MIX');
        $this->actingAsAdminLevel(StockMixLevel::ApprovalRequired);
        $needs = $this->adopt(['stock_mix' => $mix]);

        $this->assertSame(['APPROVAL_REQUIRED', 1], [$needs['effect'], $needs['approvals_needed']]);
        $row = QuotationMaterialPlanApproval::query()->where('plan_id', $needs['plan_id'])->sole();
        $this->assertSame(['STOCK_MIX', '50.00', '10.0000'], [$row->subject_code, (string) $row->facts['cost_difference_percent'], (string) $row->facts['amount_base']]);
        $this->assertSame(1, app(RequestQuotationMaterialPlanApproval::class)($this->maker, $this->revision, $this->line));
        $this->assertSame(['MATERIAL_PLAN_APPROVAL_PENDING'], $this->codes());
    }

    private function actingAsAdminLevel(StockMixLevel $level): void
    {
        app(SetStockMixPolicy::class)(MaterialSelectionScope::Company, null, $level, '10', 'Company decision', $this->admin);
    }

    public function test_the_issued_line_freezes_its_current_plan_and_the_evidence_cannot_change_or_disappear(): void
    {
        $this->adopt();
        $second = $this->adopt($this->adjust('11', $this->porous->id));
        $generation = $this->generation();

        app(IssueQuotation::class)($this->checker, $this->revision, $generation, $this->issueKey());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $issued = QuotationIssuedLine::query()->where('revision_id', $this->revision)->sole();
        $this->assertSame($second['plan_id'], $issued->material_plan_id, 'the latest adoption is the one that was sold');
        foreach (['quotation_material_plans' => ['effect', 'AUDIT_ONLY'], 'quotation_material_plan_items' => ['effect', 'AUDIT_ONLY']] as $table => [$column, $value]) {
            try {
                DB::table($table)->update([$column => $value]);
                $this->fail("{$table} must be append-only");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
            try {
                DB::table($table)->delete();
                $this->fail("{$table} of an issued quotation must not be deletable");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_database_refuses_an_issued_line_that_names_a_superseded_or_missing_plan(): void
    {
        $first = $this->adopt();
        $this->adopt($this->adjust('11', $this->porous->id));
        $generation = $this->generation();
        // Make the issue write the stale first plan: the database, not the Action, is the last word.
        $this->app->bind(MaterialPlanStanding::class, fn () => new class(app(ApprovalSubjectRegistry::class), $first['plan_id']) extends MaterialPlanStanding
        {
            public function __construct(ApprovalSubjectRegistry $subjects, private int $pinned)
            {
                parent::__construct($subjects);
            }

            public function currentPlan(int $lineId): ?QuotationMaterialPlan
            {
                return QuotationMaterialPlan::query()->find($this->pinned);
            }
        });

        $this->expectException(QueryException::class);
        app(IssueQuotation::class)($this->checker, $this->revision, $generation, $this->issueKey());
    }

    public function test_removing_the_line_removes_its_draft_plan_and_ends_its_approvals(): void
    {
        $this->approvals('MATERIAL_VARIANCE');
        $this->varianceBand('APPROVAL_REQUIRED');
        $this->adopt($this->adjust('15', $this->porous->id));
        app(RequestQuotationMaterialPlanApproval::class)($this->maker, $this->revision, $this->line);

        app(UpdateQuotationDraft::class)($this->maker, $this->revision, $this->generation(), $this->issueKey(), ['remove_lines' => [$this->line]]);

        $this->assertSame([0, 0, 0], [QuotationMaterialPlan::query()->count(), QuotationMaterialPlanItem::query()->count(), QuotationMaterialPlanApproval::query()->count()]);
        $this->assertSame(ApprovalRequestStatus::Invalidated, ApprovalRequest::query()->sole()->status);
    }

    public function test_no_evidence_table_can_be_truncated_but_a_draft_line_still_takes_its_plan_with_it(): void
    {
        $this->approvals('MATERIAL_VARIANCE');
        $this->varianceBand('APPROVAL_REQUIRED');
        $this->adopt($this->adjust('15', $this->porous->id));
        app(RevalidateQuotationMaterialPlan::class)($this->maker, $this->revision, $this->line);

        foreach (['quotation_material_plans', 'quotation_material_plan_items', 'quotation_material_plan_approvals', 'quotation_material_plan_revalidations'] as $table) {
            try {
                DB::transaction(fn () => DB::statement("TRUNCATE {$table} CASCADE"));
                $this->fail("{$table} must not be truncatable");
            } catch (QueryException $refused) {
                $this->assertStringContainsString('cannot be truncated', $refused->getMessage(), $table);
            }
            $this->assertGreaterThan(0, DB::table($table)->count(), "{$table} kept its rows");
        }

        app(UpdateQuotationDraft::class)($this->maker, $this->revision, $this->generation(), $this->issueKey(), ['remove_lines' => [$this->line]]);
        $this->assertSame([0, 0, 0, 0], [QuotationMaterialPlan::query()->count(), QuotationMaterialPlanItem::query()->count(), QuotationMaterialPlanApproval::query()->count(), QuotationMaterialPlanRevalidation::query()->count()]);
    }

    public function test_the_database_ties_an_approval_to_the_controlled_item_of_its_own_plan(): void
    {
        $this->approvals('MATERIAL_VARIANCE');
        $this->varianceBand('APPROVAL_REQUIRED');
        $first = $this->adopt($this->adjust('15', $this->porous->id));
        $second = $this->adopt($this->adjust('16', $this->porous->id));
        $firstItem = QuotationMaterialPlanItem::query()->where('plan_id', $first['plan_id'])->sole();
        $secondItem = QuotationMaterialPlanItem::query()->where('plan_id', $second['plan_id'])->sole();
        $good = ['company_id' => 1, 'revision_id' => $this->revision, 'plan_id' => $second['plan_id'], 'plan_item_id' => $secondItem->id, 'subject_code' => 'STOCK_MIX', 'facts' => json_encode(['item_id' => $this->resin->id])];
        $other = $this->receiveSecondRevision();

        $bad = [
            "another plan's item" => ['plan_id' => $first['plan_id'], 'plan_item_id' => $secondItem->id, 'subject_code' => 'MATERIAL_VARIANCE'],
            "another revision's plan" => ['revision_id' => $other, 'subject_code' => 'MATERIAL_VARIANCE'],
            'another company' => ['company_id' => 2, 'subject_code' => 'MATERIAL_VARIANCE'],
            'facts for a different material' => ['subject_code' => 'MATERIAL_VARIANCE', 'facts' => json_encode(['item_id' => $this->resin->id + 99])],
            'a stock-mix approval for an item whose stock mix needs none' => ['subject_code' => 'STOCK_MIX'],
            'an unknown kind' => ['subject_code' => 'SOMETHING_ELSE'],
        ];
        foreach ($bad as $label => $override) {
            try {
                DB::transaction(fn () => DB::table('quotation_material_plan_approvals')->insert($override + $good));
                $this->fail("the database accepted an approval with {$label}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNotSame($firstItem->id, $secondItem->id);
        $this->assertSame(1, QuotationMaterialPlanApproval::query()->where('plan_id', $second['plan_id'])->count(), 'only the genuine approval subject exists');
    }

    private function receiveSecondRevision(): int
    {
        $created = app(CreateQuotationDraft::class)($this->maker, $this->issueKey(), ['recipient_party_id' => $this->customer->id, 'pricing_date' => $this->today()]);

        return $created->revisionId;
    }

    public function test_a_plan_is_optional_until_adopted_and_then_its_freshness_check_cannot_be_skipped(): void
    {
        $this->assertSame([], $this->codes(), 'a line with no plan is priced and issued without one');

        $this->adopt();
        $this->assertSame([], $this->codes());

        // The person assessing no longer holds the preview right the re-check needs: the check cannot run, and an unknown is never a pass.
        $this->maker->revokePermissionTo('estimating.material_preview.run');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame(['MATERIAL_PLAN_NOT_REVALIDATED'], array_values(array_filter(array_map(fn (array $b): string => $b['code'], app(AssessQuotationIssueReadiness::class)(User::query()->findOrFail($this->maker->id), $this->revision)['blockers']), fn (string $c): bool => str_starts_with($c, 'MATERIAL_PLAN'))));
    }
}
