<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Modules\Sales\Actions\ChangeQuotationAccessUnit;
use App\Modules\Sales\Actions\EvaluateQuotationDiscountAuthority;
use App\Modules\Sales\Actions\RevokeDiscountAuthority;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\SalesDiscountBand;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\DiscountAuthority\AssessDiscountAuthority;
use App\Modules\Sales\Support\DiscountAuthority\DiscountConfiguration;
use App\Modules\Sales\Support\DiscountAuthority\MeasureQuotationConcessions;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\CompanyLocks\FinancialActivity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsDiscountAuthority;
use Tests\TestCase;

class DiscountAuthorityPolicyTest extends TestCase
{
    use BuildsDiscountAuthority;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDiscountAuthority();
    }

    /** @return array<string, array{int,string,string}> */
    public static function thresholds(): array
    {
        return ['zero at boundary' => [0, '0', 'NOT_APPLICABLE'], 'lead at boundary' => [1, '3', 'ROUTINE'], 'lead exceeded' => [1, '3.0001', 'APPROVAL_REQUIRED'],
            'manager at boundary' => [2, '7', 'ROUTINE'], 'manager exceeded' => [2, '8', 'APPROVAL_REQUIRED'], 'director at boundary' => [3, '12', 'ROUTINE'], 'director exceeded' => [3, '13', 'APPROVAL_REQUIRED']];
    }

    #[DataProvider('thresholds')]
    public function test_configurable_employee_percentage_boundaries(int $rank, string $percent, string $expected): void
    {
        $revision = $this->discount($this->authorityDraft(), $percent);
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, $rank);
        $this->assertSame($expected, app(AssessDiscountAuthority::class)->assess($revision)['result']);
    }

    public function test_missing_expired_revoked_or_role_mismatched_assignment_never_grants_authority(): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $policy = $this->authorityPolicy();
        $this->assertSame('APPROVAL_REQUIRED', app(AssessDiscountAuthority::class)->assess($revision)['result']);
        $expired = $this->authorityAssignment($policy, 1, over: ['effective_from' => now()->subDays(2)->format('Y-m-d'), 'effective_to' => $this->today()]);
        $this->assertSame('APPROVAL_REQUIRED', app(AssessDiscountAuthority::class)->assess($revision)['result']);
        app(RevokeDiscountAuthority::class)($this->maker, $expired->id, $this->issueKey(), $this->authorityGeneration(), 'Replace expired assignment');
        $role = Role::create(['team_id' => 1, 'name' => 'Arbitrary company role']);
        $assignment = $this->authorityAssignment($policy, 1, over: ['role_id' => $role->id]);
        $this->assertSame('APPROVAL_REQUIRED', app(AssessDiscountAuthority::class)->assess($revision)['result']);
        $this->maker->assignRole($role);
        $this->assertSame('ROUTINE', app(AssessDiscountAuthority::class)->assess($revision)['result']);
        app(RevokeDiscountAuthority::class)($this->maker, $assignment->id, $this->issueKey(), $this->authorityGeneration(), 'Authority revoked');
        $this->assertSame('APPROVAL_REQUIRED', app(AssessDiscountAuthority::class)->assess($revision)['result']);
    }

    public function test_either_monetary_or_percentage_breach_requires_higher_authority(): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $policy = $this->authorityPolicy(['bands' => [['rank' => 1, 'label' => 'Small amount ceiling', 'exception_only' => false,
            'line_percent' => '5', 'line_base_amount' => '3.0000', 'document_percent' => '5', 'document_base_amount' => '3.0000']]]);
        $this->authorityAssignment($policy, 1);
        $assessment = app(AssessDiscountAuthority::class)->assess($revision);
        $this->assertSame('4.0000', $assessment['measure']['reduction_base_amount']);
        $this->assertSame('APPROVAL_REQUIRED', $assessment['result']);
    }

    public function test_line_and_document_stacking_is_counted_once_against_the_governed_reference(): void
    {
        $revision = $this->discount($this->authorityDraft(), '10');
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey(), ['document_discount' => ['kind' => 'PERCENT', 'value' => '10']]);
        $measure = app(MeasureQuotationConcessions::class)->measure($revision->refresh());
        $this->assertSame('230.0000', $measure['reference_amount']);
        $this->assertSame('38.0000', $measure['reduction_amount']);
        $base = collect($measure['lines'])->firstWhere('counts_in_total', true);
        $this->assertSame('162.0000', $base['final_net']);
        $this->assertSame('18.0000', $base['document_allocation']);
    }

    public function test_split_lines_share_one_amount_ceiling_and_optional_prices_count_in_exposure(): void
    {
        $revision = $this->authorityDraft();
        $base = $revision->lines()->where('membership', 'BASE')->firstOrFail();
        $optional = $revision->lines()->where('membership', 'OPTIONAL')->firstOrFail();
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey(), ['update_lines' => [
            ['id' => $base->id, 'offered_quantity' => '1', 'discount' => ['kind' => 'PERCENT', 'value' => '3']],
            ['id' => $optional->id, 'discount' => ['kind' => 'PERCENT', 'value' => '3']],
        ], 'add_lines' => [['source_kind' => 'MANUAL', 'description' => 'Split', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '100.0000', 'discount' => ['kind' => 'PERCENT', 'value' => '3']]]]);
        $policy = $this->authorityPolicy(['bands' => [['rank' => 1, 'label' => 'Per economic source', 'exception_only' => false, 'line_base_amount' => '5.0000', 'document_base_amount' => '100.0000']]]);
        $this->authorityAssignment($policy, 1);
        $assessment = app(AssessDiscountAuthority::class)->assess($revision->refresh());
        $this->assertSame('6.9000', $assessment['measure']['reduction_amount']);
        $this->assertCount(2, $assessment['measure']['groups']);
        $this->assertSame('APPROVAL_REQUIRED', $assessment['result']);
    }

    public function test_every_override_and_undeclared_reduction_still_needs_independent_approval(): void
    {
        $revision = $this->authorityDraft();
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 3);
        $line = $revision->lines()->where('membership', 'BASE')->firstOrFail();
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey(), ['update_lines' => [['id' => $line->id, 'net_override' => ['amount' => '199.00', 'reason' => 'Test override']]]]);
        $this->assertSame('APPROVAL_REQUIRED', app(AssessDiscountAuthority::class)->assess($revision->refresh())['result']);
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey(), ['update_lines' => [['id' => $line->id, 'net_override' => null, 'unit_price' => '99.0000']]]);
        $assessment = app(AssessDiscountAuthority::class)->assess($revision->refresh());
        $this->assertSame('APPROVAL_REQUIRED', $assessment['result']);
        $this->assertContains('UNDECLARED_REDUCTION_REQUIRES_APPROVAL', $assessment['reasons']);
    }

    public function test_missing_policy_and_equal_priority_matches_fail_closed(): void
    {
        $revision = $this->authorityDraft();
        $this->assertContains('AUTHORITY_POLICY_MISSING', app(AssessDiscountAuthority::class)->assess($revision)['reasons']);
        $this->authorityPolicy();
        $this->authorityPolicy(['code' => 'equally-ranked']);
        $this->assertContains('AUTHORITY_POLICY_AMBIGUOUS', app(AssessDiscountAuthority::class)->assess($revision)['reasons']);
    }

    public function test_overlapping_assignments_and_mutating_used_band_versions_are_refused_by_the_database(): void
    {
        $policy = $this->authorityPolicy();
        $assignment = $this->authorityAssignment($policy, 1);
        try {
            DB::transaction(function () use ($assignment, $policy): void {
                $attributes = $assignment->getAttributes();
                unset($attributes['id'], $attributes['recorded_at']);
                $attributes['band_id'] = SalesDiscountBand::query()->where('policy_id', $policy->id)->where('rank', 2)->value('id');
                DB::table('sales_discount_assignments')->insert($attributes);
            });
            $this->fail('Overlapping assignments must refuse');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => SalesDiscountBand::create(['policy_id' => $policy->id, 'rank' => 99, 'label' => 'Late extra authority', 'line_percent' => '100']));
            $this->fail('Activated versions must not grow new bands');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
    }

    public function test_shadow_evaluations_are_immutable_idempotent_and_do_not_clear_existing_approval_need(): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 1);
        $this->authorityMode();
        $result = app(EvaluateQuotationDiscountAuthority::class)($this->maker, $revision->id, $revision->input_generation, 'shadow-evaluation');
        $again = app(EvaluateQuotationDiscountAuthority::class)($this->maker, $revision->id, $revision->input_generation, 'shadow-evaluation');
        $this->assertSame($result, $again);
        $this->assertSame('ROUTINE', $result['result']);
        $this->assertDatabaseCount('sales_discount_evaluations', 1);
        $this->assertTrue($revision->lines()->where('membership', 'BASE')->firstOrFail()->approval_required);
        $this->expectException(QueryException::class);
        DB::table('sales_discount_evaluations')->where('id', $result['evaluation_id'])->delete();
    }

    public function test_the_default_mode_is_off_and_disabling_does_not_delete_evidence(): void
    {
        $this->assertSame('OFF', app(DiscountConfiguration::class)->mode());
        $this->authorityMode();
        $this->authorityMode('OFF');
        $this->assertDatabaseCount('sales_discount_mode_events', 2);
        $this->assertSame('OFF', app(DiscountConfiguration::class)->mode());
        $this->expectException(DomainInvariantException::class);
        $revision = $this->authorityDraft();
        app(EvaluateQuotationDiscountAuthority::class)($this->maker, $revision->id, $revision->input_generation, 'off-evaluation');
    }

    /** @return array<string, array{string,string}> */
    public static function enforcementModes(): array
    {
        return ['line only' => ['LINE', 'ROUTINE'], 'document only' => ['DOCUMENT', 'ROUTINE'], 'both' => ['BOTH', 'APPROVAL_REQUIRED']];
    }

    #[DataProvider('enforcementModes')]
    public function test_company_can_select_line_document_or_both_enforcement(string $mode, string $expected): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $policy = $this->authorityPolicy(['enforcement' => $mode, 'bands' => [['rank' => 1, 'label' => 'Configured percent only', 'exception_only' => false,
            'line_percent' => $mode === 'DOCUMENT' ? '0' : '3', 'document_percent' => $mode === 'DOCUMENT' ? '3' : '0']]]);
        $this->authorityAssignment($policy, 1);
        $this->assertSame($expected, app(AssessDiscountAuthority::class)->assess($revision)['result']);
    }

    public function test_unit_authority_needs_both_the_matching_family_unit_and_active_membership(): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $policy = $this->authorityPolicy();
        $unit = OrganisationUnit::create(['code' => 'test-branch', 'name' => 'Test branch', 'status' => 'ACTIVE']);
        $this->authorityAssignment($policy, 1, over: ['unit_id' => $unit->id]);
        $this->assertSame('APPROVAL_REQUIRED', app(AssessDiscountAuthority::class)->assess($revision)['result']);
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated([PermissionVocabulary::QUOTATION_ASSIGN]));
        Permission::findOrCreate(PermissionVocabulary::QUOTATION_ASSIGN, 'web');
        $this->maker->givePermissionTo(PermissionVocabulary::QUOTATION_ASSIGN);
        $family = $revision->family;
        app(ChangeQuotationAccessUnit::class)($this->maker, $family->id, $unit->id, $family->ownership_generation, $family->access_generation, $this->issueKey(), 'Test explicit branch');
        $revision->unsetRelation('family');
        $this->assertSame('APPROVAL_REQUIRED', app(AssessDiscountAuthority::class)->assess($revision)['result']);
        DB::table('organisation_unit_members')->insert(['company_id' => 1, 'unit_id' => $unit->id, 'user_id' => $this->maker->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('ROUTINE', app(AssessDiscountAuthority::class)->assess($revision)['result']);
        $unit->update(['status' => 'ARCHIVED']);
        $this->assertSame('APPROVAL_REQUIRED', app(AssessDiscountAuthority::class)->assess($revision)['result']);
    }

    public function test_revoking_the_entrants_discount_duty_invalidates_routine_clearance(): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 1);
        $this->assertSame('ROUTINE', app(AssessDiscountAuthority::class)->assess($revision)['result']);
        $this->maker->revokePermissionTo(PermissionVocabulary::QUOTATION_DISCOUNT);
        $this->assertSame('APPROVAL_REQUIRED', app(AssessDiscountAuthority::class)->assess($revision)['result']);
    }

    public function test_changing_a_bound_deliverable_measure_cannot_select_a_cheaper_project_baseline(): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $this->authorityPolicy();
        $base = $revision->lines()->where('membership', 'BASE')->firstOrFail();
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey(), ['update_lines' => [['id' => $base->id, 'manual_measure' => 'extra']]]);
        $this->assertContains('REFERENCE_CHANGED', app(AssessDiscountAuthority::class)->assess($revision->refresh())['reasons']);
    }

    public function test_an_issuers_higher_assignment_cannot_sponsor_an_unassigned_concession_entrant(): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $policy = $this->authorityPolicy();
        $this->checker->givePermissionTo(PermissionVocabulary::QUOTATION_DISCOUNT);
        $this->authorityAssignment($policy, 3, $this->checker);
        $assessment = app(AssessDiscountAuthority::class)->assess($revision);
        $this->assertSame('APPROVAL_REQUIRED', $assessment['result']);
        $this->assertSame([$this->maker->id => null], $assessment['assignments']);
    }

    public function test_an_expired_successor_policy_does_not_resurrect_its_predecessor(): void
    {
        $revision = $this->authorityDraft();
        $this->authorityPolicy();
        $this->authorityPolicy(['effective_from' => now()->addDay()->format('Y-m-d'), 'effective_to' => now()->addDays(2)->format('Y-m-d')]);
        $this->travelTo(now()->addDays(3));
        try {
            $this->assertContains('AUTHORITY_POLICY_MISSING', app(AssessDiscountAuthority::class)->assess($revision)['reasons']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_unmigrated_staged_tables_do_not_break_the_existing_company_currency_probe(): void
    {
        DB::statement('ALTER TABLE sales_discount_policy_versions RENAME TO codex_staged_discount_policies');
        DB::statement('ALTER TABLE sales_discount_bands RENAME TO codex_staged_discount_bands');
        DB::statement('ALTER TABLE sales_price_reference_versions RENAME TO codex_staged_price_references');
        try {
            $this->assertIsArray(app(FinancialActivity::class)->existing());
        } finally {
            DB::statement('ALTER TABLE codex_staged_price_references RENAME TO sales_price_reference_versions');
            DB::statement('ALTER TABLE codex_staged_discount_bands RENAME TO sales_discount_bands');
            DB::statement('ALTER TABLE codex_staged_discount_policies RENAME TO sales_discount_policy_versions');
        }
    }
}
