<?php

namespace Tests\Feature\Support;

use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\ReassignQuotationOwner;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Support\Authority\DutyScope;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationFamilyScopeAdapter;
use App\Support\Scope\RecordScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

class QuotationOwnScopeTest extends TestCase
{
    use BuildsQuotationOwnership, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    public function test_own_is_current_responsible_owner_not_creator_and_unresolved_is_excluded(): void
    {
        $family = $this->nativeFamily();
        $this->legacyFamily();
        AccessScopeLimit::create(['user_id' => $this->preparer->id, 'permission' => PermissionVocabulary::QUOTATION_VIEW, 'scope' => 'OWN']);
        $resolver = app(RecordScope::class);
        $scope = $resolver->resolveForResource($this->preparer, PermissionVocabulary::QUOTATION_VIEW, 'quotation_family');
        $this->assertTrue($scope->ownsRecords());
        $this->assertTrue($resolver->resolve($this->preparer, PermissionVocabulary::QUOTATION_VIEW)->isNone());
        $query = app(QuotationFamilyScopeAdapter::class)->apply(QuotationFamily::query(), $scope, $this->preparer->id);
        $this->assertSame([$family->id], $query->pluck('id')->all());
        app(ReassignQuotationOwner::class)($this->assigner, $family->id, $this->colleague->id, 1, 0, $this->key(), 'handover');
        $this->assertSame([], app(QuotationFamilyScopeAdapter::class)->apply(QuotationFamily::query(), $scope, $this->preparer->id)->pluck('id')->all());
        $this->assertSame($this->preparer->id, $family->fresh()->created_by);
    }

    public function test_unsupported_modes_and_unknown_resource_fail_closed_but_same_duty_unrestricted_wins(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionVocabulary::QUOTATION_VIEW);
        $limit = AccessScopeLimit::create(['user_id' => $user->id, 'permission' => PermissionVocabulary::QUOTATION_VIEW, 'scope' => 'OWN_OR_DELEGATED']);
        $resolver = app(RecordScope::class);
        $this->assertTrue($resolver->resolveForResource($user, PermissionVocabulary::QUOTATION_VIEW, 'quotation_family')->isNone());
        $this->assertTrue($resolver->resolve($user, PermissionVocabulary::QUOTATION_VIEW)->isNone());
        $limit->update(['scope' => 'MY_UNITS']);
        $this->assertTrue($resolver->resolveForResource($user, PermissionVocabulary::QUOTATION_VIEW, 'quotation_family')->isNone());
        $role = Role::create(['team_id' => 1, 'name' => 'full quote view']);
        $role->givePermissionTo(PermissionVocabulary::QUOTATION_VIEW);
        $user->assignRole($role);
        $this->assertTrue($resolver->resolveForResource($user, PermissionVocabulary::QUOTATION_VIEW, 'quotation_family')->isAll());
        $this->assertTrue($resolver->resolveForResource($user, PermissionVocabulary::QUOTATION_VIEW, 'unknown')->isNone());
        $this->assertTrue($resolver->resolveForResource($user, PermissionVocabulary::QUOTATION_ASSIGN, 'quotation_family')->isNone());
    }

    public function test_all_and_own_adapter_queries_keep_the_company_boundary_even_without_model_scopes(): void
    {
        $family = $this->nativeFamily();
        $foreign = $this->foreignFamilyId();
        $adapter = app(QuotationFamilyScopeAdapter::class);
        $all = app(RecordScope::class)->resolveForResource($this->preparer, PermissionVocabulary::QUOTATION_VIEW, 'quotation_family');
        $this->assertSame([$family->id], $adapter->apply(QuotationFamily::withoutGlobalScopes(), $all, $this->preparer->id)->pluck('id')->all());
        $this->assertFalse($adapter->apply(QuotationFamily::withoutGlobalScopes(), $all, $this->preparer->id)->whereKey($foreign)->exists());
        $foreignUser = User::withoutGlobalScopes()->findOrFail($this->otherUser);
        $this->assertTrue(app(RecordScope::class)->resolveForResource($foreignUser, PermissionVocabulary::QUOTATION_VIEW, 'quotation_family')->isNone());
    }

    public function test_shipped_quotation_scope_metadata_remains_staged(): void
    {
        $manifest = SalesDutyManifest::shipped();
        foreach ([PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_ASSIGN] as $duty) {
            $this->assertFalse($manifest->isActive($duty));
            $this->assertSame(DutyScope::QuotationFamily, $manifest->find($duty)->scope);
        }
        $this->assertSame(DutyScope::CompanyWide, $manifest->find(PermissionVocabulary::PIPELINE_VIEW)->scope);
        $preview = $manifest->withActivated([PermissionVocabulary::QUOTATION_VIEW]);
        $this->assertSame(DutyScope::QuotationFamily, $preview->find(PermissionVocabulary::QUOTATION_VIEW)->scope);
        $this->assertTrue($preview->isActive(PermissionVocabulary::QUOTATION_VIEW));
        $this->assertFalse($manifest->isActive(PermissionVocabulary::QUOTATION_VIEW));
    }

    public function test_revoked_grant_and_removed_limit_do_not_widen_a_stale_hydrated_actor(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionVocabulary::QUOTATION_VIEW);
        $limit = AccessScopeLimit::create(['user_id' => $user->id, 'permission' => PermissionVocabulary::QUOTATION_VIEW, 'scope' => 'OWN']);
        $user->load('permissions', 'roles');
        $stale = clone $user;
        $user->revokePermissionTo(PermissionVocabulary::QUOTATION_VIEW);
        $limit->delete();
        $resolver = app(RecordScope::class);
        $this->assertTrue($resolver->resolveForResource($stale, PermissionVocabulary::QUOTATION_VIEW, 'quotation_family')->isNone());
    }

    public function test_scope_rollback_refuses_to_delete_live_new_limits(): void
    {
        AccessScopeLimit::create(['user_id' => $this->preparer->id, 'permission' => PermissionVocabulary::QUOTATION_VIEW, 'scope' => 'OWN']);
        $migration = require database_path('migrations/2026_10_30_110000_extend_access_scope_limits_for_quotation_own.php');
        $this->assertThrows(fn () => DB::transaction(fn () => $migration->down()), \RuntimeException::class);
        $this->assertDatabaseHas('access_scope_limits', ['user_id' => $this->preparer->id, 'permission' => PermissionVocabulary::QUOTATION_VIEW, 'scope' => 'OWN']);
    }

    public function test_mixed_role_own_is_refused_instead_of_widening_other_duties(): void
    {
        Permission::findOrCreate('inventory.stock.view');
        $user = User::factory()->create();
        $role = Role::create(['team_id' => 1, 'name' => 'mixed']);
        $role->givePermissionTo([PermissionVocabulary::QUOTATION_VIEW, 'inventory.stock.view']);
        $user->assignRole($role);
        AccessScopeLimit::create(['user_id' => $user->id, 'role_id' => $role->id, 'scope' => 'OWN']);
        $resolver = app(RecordScope::class);
        $this->assertTrue($resolver->resolveForResource($user, PermissionVocabulary::QUOTATION_VIEW, 'quotation_family')->isNone());
        $this->assertTrue($resolver->resolve($user, 'inventory.stock.view')->isNone());
    }
}
