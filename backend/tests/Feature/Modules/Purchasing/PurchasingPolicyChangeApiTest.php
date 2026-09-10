<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Read-only audit trail for PurchasingSettings policy changes — every row is
 * written by App\Modules\Purchasing\Actions\UpdatePurchasingSettings; there
 * is nothing to write here directly. Reading it needs its own
 * `purchasing.policy-history.view` permission, deliberately separate from
 * `purchasing.over-receipt-policy.manage` — who changed a policy, why and
 * when is more sensitive than the current settings, which any user who
 * needs them can already see.
 */
class PurchasingPolicyChangeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('purchasing.over-receipt-policy.manage');
        Permission::findOrCreate('purchasing.policy-history.view');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/purchasing-policy-changes')->assertUnauthorized();
    }

    public function test_index_requires_the_policy_history_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/purchasing-policy-changes')->assertForbidden();
    }

    public function test_the_settings_manage_permission_alone_does_not_grant_history_access(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchasing.over-receipt-policy.manage');
        $this->actingAs($user)->getJson('/api/v1/purchasing-settings')->assertOk();
        $this->patchJson('/api/v1/purchasing-settings', [
            'over_receipt_mode' => 'ALLOW_WITHIN_TOLERANCE',
            'over_receipt_tolerance_percent' => '5',
            'reason' => 'Allow a small receiving tolerance',
        ])->assertOk();

        // Same user, same company, real policy-change row now exists — but no
        // purchasing.policy-history.view permission, so the audit trail stays hidden.
        $this->getJson('/api/v1/purchasing-policy-changes')->assertForbidden();
    }

    public function test_index_reflects_a_real_policy_change(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchasing.over-receipt-policy.manage', 'purchasing.policy-history.view');

        // PurchasingSetting is lazily provisioned on first touch (firstOrCreate) —
        // a real user always GETs the settings screen before editing it, so this
        // matches that sequence rather than letting the PATCH itself be the row's
        // first-ever creation (which correctly 201s, not 200, per Laravel's
        // wasRecentlyCreated-based resource status).
        $this->actingAs($user)->getJson('/api/v1/purchasing-settings')->assertOk();

        $this->patchJson('/api/v1/purchasing-settings', [
            'over_receipt_mode' => 'ALLOW_WITHIN_TOLERANCE',
            'over_receipt_tolerance_percent' => '5',
            'reason' => 'Allow a small receiving tolerance',
        ])->assertOk();

        $response = $this->getJson('/api/v1/purchasing-policy-changes');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.policy', 'OVER_RECEIPT');
        $response->assertJsonPath('data.0.reason', 'Allow a small receiving tolerance');
        $response->assertJsonPath('data.0.new_values.over_receipt_mode', 'ALLOW_WITHIN_TOLERANCE');
    }

    public function test_index_filters_by_policy(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchasing.over-receipt-policy.manage', 'purchasing.policy-history.view');
        $this->actingAs($user)->getJson('/api/v1/purchasing-settings')->assertOk();
        $this->patchJson('/api/v1/purchasing-settings', [
            'over_receipt_mode' => 'ALLOW_WITHIN_TOLERANCE',
            'over_receipt_tolerance_percent' => '5',
            'reason' => 'Allow a small receiving tolerance',
        ])->assertOk();

        $this->getJson('/api/v1/purchasing-policy-changes?filter[policy]=OVER_RECEIPT')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/purchasing-policy-changes?filter[policy]=BOGUS')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_index_rejects_invalid_sort(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchasing.policy-history.view');

        $this->actingAs($user)
            ->getJson('/api/v1/purchasing-policy-changes?sort=policy')
            ->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_another_companys_policy_change_is_not_visible(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('purchasing.over-receipt-policy.manage', 'purchasing.policy-history.view');
        $this->actingAs($user)->getJson('/api/v1/purchasing-settings')->assertOk();
        $this->patchJson('/api/v1/purchasing-settings', [
            'over_receipt_mode' => 'ALLOW_WITHIN_TOLERANCE',
            'over_receipt_tolerance_percent' => '5',
            'reason' => 'Allow a small receiving tolerance',
        ])->assertOk();

        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);

        // SetPermissionsTeam middleware derives the live permission team from
        // CompanyContext on every request, so this grant must be made under the
        // other company's team id — granting it while team 1 is still active (as
        // set in setUp) would store a permission the other company's own
        // requests, scoped to team $otherCompanyId, can never see.
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherCompanyId);
        $otherCompanyUser = User::factory()->create();
        $otherCompanyUser->givePermissionTo('purchasing.policy-history.view');

        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->actingAs($otherCompanyUser)->getJson('/api/v1/purchasing-policy-changes')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
