<?php

namespace Tests\Feature\Modules\Estimating;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Modules\Estimating\Actions\CreateMaterialVariancePolicy;
use App\Modules\Estimating\Actions\PreviewMaterialVariance;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * The `APPROVAL_REQUIRED` variance effect (ADR 0054 §7, ADR 0070 §11): offered only while approvals are switched on for
 * material variance, fail-closed if they are switched off afterwards, and previewed — never submitted — until Phase 4.
 */
class MaterialVarianceApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Item $paint;

    private User $user;

    private Uom $litre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'PAINTS', 'name' => 'Paints', 'default_valuation_method' => 'AVCO']);
        $this->paint = Item::create(['code' => 'PAINT', 'name' => 'Paint', 'type' => 'RAW', 'stock_uom_id' => $this->litre->id, 'category_id' => $category->id]);
        $this->user = User::factory()->create();
    }

    private function mode(ApprovalMode $mode): void
    {
        ApprovalSubjectSetting::query()->updateOrCreate(['subject_type' => 'MATERIAL_VARIANCE'], ['mode' => $mode]);
    }

    private function companyPolicy(string $effect): void
    {
        app(CreateMaterialVariancePolicy::class)([
            'scope_type' => 'COMPANY', 'scope_id' => null, 'reason' => 'Test',
            'bands' => [['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '20', 'uom_id' => null, 'effect' => $effect]],
        ], PermissionedActor::grant($this->user, ['estimating.material_variance_policy.manage']));
        MaterialVariancePolicy::query()->update(['status' => 'ACTIVE', 'effective_from' => now()->subDay()->toDateString()]);
    }

    private function approvalPolicy(): void
    {
        $policy = ApprovalPolicy::query()->create(['subject_type' => 'MATERIAL_VARIANCE', 'code' => 'variance', 'version_no' => 1, 'name' => 'Variance sign-off', 'priority' => 0, 'status' => ApprovalPolicyStatus::Active, 'reason' => 't', 'changed_by' => $this->user->id]);
        $policy->steps()->create(['stage' => 1, 'name' => 'Estimating lead', 'required_approvals' => 1]);
        $policy->steps()->create(['stage' => 2, 'name' => 'Finance', 'required_approvals' => 2, 'use_authority_limit' => true]);
    }

    /** @return array{0: array<string, mixed>, 1: array<int, string>, 2: list<array<string, mixed>>} */
    private function preview(string $standard, string $planned): array
    {
        $leaf = ['item' => ['id' => $this->paint->id, 'code' => 'PAINT', 'name' => 'Paint'], 'standard_quantity' => $standard, 'uom' => ['id' => $this->litre->id, 'code' => 'L', 'name' => 'Litre']];

        return app(PreviewMaterialVariance::class)([$leaf], [['item_id' => $this->paint->id, 'planned_quantity' => $planned, 'uom_id' => $this->litre->id]], PermissionedActor::grant(User::factory()->create(), ['estimating.material_preview.run']));
    }

    public function test_the_approval_effect_can_be_chosen_only_while_approvals_are_switched_on(): void
    {
        try {
            $this->companyPolicy('APPROVAL_REQUIRED');
            $this->fail('Approval-required must be refused while approvals are off for material variance.');
        } catch (DomainInvariantException $e) {
            $this->assertStringContainsString('switched on', $e->getMessage());
        }

        $this->mode(ApprovalMode::Policy);
        $this->companyPolicy('APPROVAL_REQUIRED');
        $this->assertSame(1, MaterialVariancePolicy::query()->count());
    }

    public function test_a_stored_approval_band_fails_closed_if_approvals_are_switched_off_later(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->companyPolicy('APPROVAL_REQUIRED');
        $this->mode(ApprovalMode::None);

        [$data] = $this->preview('10', '15');
        $item = $data['items'][0];

        $this->assertSame('BLOCKED', $item['effect']);
        $this->assertFalse($item['can_adopt']);
        $this->assertStringContainsString('approval workflow is not available', $item['limitations'][0]);
    }

    public function test_a_plan_in_an_approval_band_says_which_approval_it_would_need_and_cannot_be_adopted(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->companyPolicy('APPROVAL_REQUIRED');
        $this->approvalPolicy();

        [$data] = $this->preview('10', '15');
        $item = $data['items'][0];

        $this->assertSame('APPROVAL_REQUIRED', $item['effect']);
        $this->assertSame('APPROVAL_NEEDED', $item['reason_status']);
        $this->assertFalse($item['can_adopt']);
        $this->assertSame('Variance sign-off', $item['approval']['policy']['name']);
        $this->assertSame([['Estimating lead', 1, 1, false], ['Finance', 2, 2, true]], array_map(fn (array $s): array => [$s['name'], $s['stage'], $s['required_approvals'], $s['uses_authority_limit']], $item['approval']['steps']));
        $this->assertSame(1, $data['summary']['needs_approval_count']);
        $this->assertFalse($data['summary']['can_adopt']);
    }

    public function test_with_no_matching_approval_policy_the_plan_is_never_treated_as_not_required(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->companyPolicy('APPROVAL_REQUIRED');

        [$data] = $this->preview('10', '15');
        $item = $data['items'][0];

        $this->assertNull($item['approval']['policy']);
        $this->assertSame([], $item['approval']['steps']);
        $this->assertStringContainsString('cannot be adopted until one is configured', $item['approval']['note']);
        $this->assertFalse($item['can_adopt']);
    }

    public function test_a_difference_below_the_band_needs_no_approval(): void
    {
        $this->mode(ApprovalMode::Policy);
        $this->companyPolicy('APPROVAL_REQUIRED');

        [$data] = $this->preview('10', '11');

        $this->assertNull($data['items'][0]['approval']);
        $this->assertSame(0, $data['summary']['needs_approval_count']);
    }

    public function test_the_screens_can_ask_whether_the_approval_effect_is_offered(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('estimating.material_preview.run');
        $runner = User::factory()->create();
        $runner->givePermissionTo('estimating.material_preview.run');

        $this->actingAs(User::factory()->create())->getJson('/api/v1/material-variance-capabilities')->assertForbidden();
        $this->actingAs($runner)->getJson('/api/v1/material-variance-capabilities')->assertOk()->assertJsonPath('data.approval_required_available', false);
        $this->mode(ApprovalMode::Required);
        $this->getJson('/api/v1/material-variance-capabilities')->assertJsonPath('data.approval_required_available', true);
    }
}
