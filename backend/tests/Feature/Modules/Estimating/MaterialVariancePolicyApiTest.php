<?php

namespace Tests\Feature\Modules\Estimating;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\CreateMaterialVariancePolicy;
use App\Modules\Estimating\Actions\ResolveMaterialVariancePolicy;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Estimating\Models\MaterialVarianceReason;
use App\Modules\Estimating\Support\VarianceResolutionMethod;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MaterialVariancePolicyApiTest extends TestCase
{
    use RefreshDatabase;

    private Uom $litre;

    private Category $paints;

    private Item $paint;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['estimating.material_variance_policy.manage', 'estimating.policy-history.view', 'estimating.material_preview.run'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '1', 'is_base' => true]);
        $this->paints = Category::create(['code' => 'PAINTS', 'name' => 'Paints', 'default_valuation_method' => 'AVCO']);
        $this->paint = Item::create(['code' => 'PAINT', 'name' => 'Paint', 'type' => 'RAW', 'stock_uom_id' => $this->litre->id, 'category_id' => $this->paints->id]);
        $this->manager = $this->userWith('estimating.material_variance_policy.manage');
    }

    // ---- reasons ----

    public function test_a_manager_creates_a_reason_whose_code_is_normalised_and_unique_per_company(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/material-variance-reasons', ['code' => '  Porous-Surface ', 'name' => 'Porous surface'])
            ->assertCreated()->assertJsonPath('data.code', 'porous-surface')->assertJsonPath('data.active', true);

        $this->actingAs($this->manager)->postJson('/api/v1/material-variance-reasons', ['code' => 'POROUS-SURFACE', 'name' => 'Again'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($this->manager)->postJson('/api/v1/material-variance-reasons', ['code' => 'has spaces', 'name' => 'Bad'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_a_reason_is_retired_not_deleted_and_its_code_cannot_change(): void
    {
        $reason = MaterialVarianceReason::create(['code' => 'wastage', 'name' => 'Wastage']);

        $this->actingAs($this->manager)->patchJson("/api/v1/material-variance-reasons/{$reason->id}", ['active' => false, 'name' => 'Wastage allowance', 'code' => 'renamed'])
            ->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.name', 'Wastage allowance')->assertJsonPath('data.code', 'wastage');
        $this->actingAs($this->manager)->deleteJson("/api/v1/material-variance-reasons/{$reason->id}")->assertStatus(405);
    }

    public function test_reasons_can_be_read_by_a_previewer_but_only_written_by_a_manager(): void
    {
        MaterialVarianceReason::create(['code' => 'a', 'name' => 'A']);
        MaterialVarianceReason::create(['code' => 'b', 'name' => 'B', 'active' => false]);
        $previewer = $this->userWith('estimating.material_preview.run');
        $nobody = User::factory()->create();

        $this->actingAs($previewer)->getJson('/api/v1/material-variance-reasons')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($previewer)->getJson('/api/v1/material-variance-reasons?filter[active]=true')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'a');
        $this->actingAs($previewer)->postJson('/api/v1/material-variance-reasons', ['code' => 'c', 'name' => 'C'])->assertForbidden();
        $this->actingAs($nobody)->getJson('/api/v1/material-variance-reasons')->assertForbidden();
    }

    // ---- policies: writing ----

    public function test_a_manager_creates_a_draft_policy_with_its_bands(): void
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/material-variance-policies', $this->payload());

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'DRAFT');
        $response->assertJsonPath('data.minimum_effect', 'REASON_REQUIRED');
        $response->assertJsonPath('data.changed_by', $this->manager->id);
        $response->assertJsonCount(3, 'data.bands');
        $response->assertJsonPath('data.bands.2.uom.code', 'L');
        $response->assertJsonPath('data.bands.0.effect_label', 'Reason required');
    }

    public function test_writing_a_policy_needs_the_manage_permission(): void
    {
        $historyOnly = $this->userWith('estimating.policy-history.view');

        $this->actingAs($historyOnly)->postJson('/api/v1/material-variance-policies', $this->payload())->assertForbidden();
        $policy = $this->draft();
        $this->actingAs($historyOnly)->patchJson("/api/v1/material-variance-policies/{$policy->id}", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($historyOnly)->deleteJson("/api/v1/material-variance-policies/{$policy->id}")->assertUnprocessable();
        $this->actingAs($historyOnly)->postJson("/api/v1/material-variance-policies/{$policy->id}/activate")->assertUnprocessable();
        $this->assertSame('DRAFT', $policy->fresh()->status->value);
    }

    public function test_approval_required_cannot_be_chosen_anywhere(): void
    {
        $band = $this->payload(bands: [['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '10', 'effect' => 'APPROVAL_REQUIRED']]);
        $this->actingAs($this->manager)->postJson('/api/v1/material-variance-policies', $band)
            ->assertUnprocessable()->assertJsonValidationErrors('bands.0.effect');

        $this->actingAs($this->manager)->postJson('/api/v1/material-variance-policies', $this->payload(extra: ['minimum_effect' => 'APPROVAL_REQUIRED']))
            ->assertUnprocessable()->assertJsonValidationErrors('minimum_effect');
    }

    public function test_the_action_refuses_approval_required_even_when_called_without_the_http_layer(): void
    {
        $this->expectException(DomainInvariantException::class);

        app(CreateMaterialVariancePolicy::class)(
            ['scope_type' => 'COMPANY', 'reason' => 'x', 'bands' => [['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '10', 'effect' => 'APPROVAL_REQUIRED']]],
            $this->manager,
        );
    }

    public function test_band_shapes_are_validated(): void
    {
        $cases = [
            'a quantity band needs a unit' => [['direction' => 'OVER', 'measure' => 'QUANTITY', 'threshold' => '2', 'effect' => 'BLOCKED'], 'bands.0.uom_id'],
            'a percent band has no unit' => [['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '2', 'uom_id' => $this->litre->id, 'effect' => 'BLOCKED'], 'bands.0.uom_id'],
            'a negative threshold' => [['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '-1', 'effect' => 'BLOCKED'], 'bands.0.threshold'],
            'under-plan percent above 100' => [['direction' => 'UNDER', 'measure' => 'PERCENT', 'threshold' => '101', 'effect' => 'BLOCKED'], 'bands.0.threshold'],
            'an unknown measure' => [['direction' => 'OVER', 'measure' => 'WASTE', 'threshold' => '1', 'effect' => 'BLOCKED'], 'bands.0.measure'],
        ];

        foreach ($cases as $label => [$band, $errorKey]) {
            $this->actingAs($this->manager)->postJson('/api/v1/material-variance-policies', $this->payload(bands: [$band]))
                ->assertUnprocessable($label)->assertJsonValidationErrors($errorKey);
        }

        // Over-plan percentages may exceed 100: planned can be triple the standard.
        $this->actingAs($this->manager)->postJson('/api/v1/material-variance-policies', $this->payload(bands: [['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '250', 'effect' => 'BLOCKED']]))->assertCreated();
    }

    public function test_the_scope_must_exist_and_a_company_policy_has_none(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/material-variance-policies', $this->payload(scope: ['scope_type' => 'CATEGORY', 'scope_id' => 99999]))
            ->assertUnprocessable()->assertJsonValidationErrors('scope_id');
        $this->actingAs($this->manager)->postJson('/api/v1/material-variance-policies', $this->payload(scope: ['scope_type' => 'ITEM']))
            ->assertUnprocessable()->assertJsonValidationErrors('scope_id');
        $this->actingAs($this->manager)->postJson('/api/v1/material-variance-policies', $this->payload(scope: ['scope_type' => 'COMPANY', 'scope_id' => $this->paints->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('scope_id');
        $this->actingAs($this->manager)->postJson('/api/v1/material-variance-policies', $this->payload(scope: ['scope_type' => 'ITEM', 'scope_id' => $this->paint->id]))->assertCreated();
    }

    public function test_an_identical_band_twice_is_rejected_not_silently_kept(): void
    {
        $band = ['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '10', 'effect' => 'REASON_REQUIRED'];

        $this->actingAs($this->manager)->postJson('/api/v1/material-variance-policies', $this->payload(bands: [$band, $band]))->assertUnprocessable();
        $this->assertSame(0, MaterialVariancePolicy::query()->count());
    }

    // ---- policies: lifecycle ----

    public function test_a_draft_is_edited_with_its_band_set_replaced_wholesale(): void
    {
        $policy = $this->draft();

        $response = $this->actingAs($this->manager)->patchJson("/api/v1/material-variance-policies/{$policy->id}", [
            'reason' => 'Tightened after the audit',
            'bands' => [['direction' => 'UNDER', 'measure' => 'PERCENT', 'threshold' => '20', 'effect' => 'REASON_REQUIRED']],
        ]);

        $response->assertOk()->assertJsonPath('data.reason', 'Tightened after the audit')->assertJsonCount(1, 'data.bands');
        $this->assertSame(1, $policy->bands()->count());
        // Omitting `bands` leaves them alone.
        $this->actingAs($this->manager)->patchJson("/api/v1/material-variance-policies/{$policy->id}", ['minimum_effect' => null])->assertOk()->assertJsonCount(1, 'data.bands')->assertJsonPath('data.minimum_effect', null);
    }

    public function test_scope_cannot_be_changed_by_an_update(): void
    {
        $policy = $this->draft();

        $this->actingAs($this->manager)->patchJson("/api/v1/material-variance-policies/{$policy->id}", ['scope_type' => 'ITEM', 'scope_id' => $this->paint->id])->assertOk();

        $this->assertSame('COMPANY', $policy->fresh()->scope_type->value);
    }

    public function test_activation_supersedes_the_previous_version_and_keeps_it_as_history(): void
    {
        $first = $this->draft();
        $this->actingAs($this->manager)->postJson("/api/v1/material-variance-policies/{$first->id}/activate")
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.effective_from', now()->toDateString());

        $second = $this->draft();
        $this->actingAs($this->manager)->postJson("/api/v1/material-variance-policies/{$second->id}/activate")->assertOk();

        $this->assertSame(['SUPERSEDED', now()->toDateString()], [$first->fresh()->status->value, $first->fresh()->effective_to->toDateString()]);
        $this->assertSame('ACTIVE', $second->fresh()->status->value);
        $this->assertSame($second->id, (new ResolveMaterialVariancePolicy)($this->paint->fresh())->policy->id);
        $this->assertSame(VarianceResolutionMethod::Company, (new ResolveMaterialVariancePolicy)($this->paint->fresh())->method);
        // The old version, and its bands, are still there.
        $this->assertSame(3, $first->bands()->count());
    }

    public function test_only_a_draft_can_be_edited_activated_or_deleted(): void
    {
        $policy = $this->draft();
        $this->actingAs($this->manager)->postJson("/api/v1/material-variance-policies/{$policy->id}/activate")->assertOk();

        $this->actingAs($this->manager)->patchJson("/api/v1/material-variance-policies/{$policy->id}", ['reason' => 'x'])->assertUnprocessable();
        $this->actingAs($this->manager)->deleteJson("/api/v1/material-variance-policies/{$policy->id}")->assertUnprocessable();
        $this->actingAs($this->manager)->postJson("/api/v1/material-variance-policies/{$policy->id}/activate")->assertUnprocessable();

        $draft = $this->draft();
        $this->actingAs($this->manager)->deleteJson("/api/v1/material-variance-policies/{$draft->id}")->assertNoContent();
        $this->assertNull(MaterialVariancePolicy::query()->find($draft->id));
        $this->assertSame(0, DB::table('material_variance_policy_bands')->where('policy_id', $draft->id)->count());
    }

    public function test_listing_is_audit_detail_gated_like_overhead_history(): void
    {
        $this->draft();
        $reader = $this->userWith('estimating.policy-history.view');
        $previewer = $this->userWith('estimating.material_preview.run');

        $this->actingAs($reader)->getJson('/api/v1/material-variance-policies?include=bands')->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(3, 'data.0.bands');
        $this->actingAs($reader)->getJson('/api/v1/material-variance-policies?filter[status]=ACTIVE')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($previewer)->getJson('/api/v1/material-variance-policies')->assertForbidden();
        $this->actingAs($reader)->getJson('/api/v1/material-variance-policies?filter[status]=NOPE')->assertUnprocessable();
    }

    private function draft(): MaterialVariancePolicy
    {
        $id = $this->actingAs($this->manager)->postJson('/api/v1/material-variance-policies', $this->payload())->assertCreated()->json('data.id');

        return MaterialVariancePolicy::query()->findOrFail($id);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(...$permissions);

        return $user;
    }

    /**
     * @param  list<array<string, mixed>>|null  $bands
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(?array $bands = null, array $scope = ['scope_type' => 'COMPANY'], array $extra = ['minimum_effect' => 'REASON_REQUIRED']): array
    {
        return array_merge($scope, $extra, [
            'reason' => 'Initial company rule',
            'bands' => $bands ?? [
                ['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '10', 'effect' => 'REASON_REQUIRED'],
                ['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '30', 'effect' => 'BLOCKED'],
                ['direction' => 'OVER', 'measure' => 'QUANTITY', 'threshold' => '2', 'uom_id' => $this->litre->id, 'effect' => 'REASON_REQUIRED'],
            ],
        ]);
    }
}
