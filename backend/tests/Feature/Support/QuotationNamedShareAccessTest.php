<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\ReassignQuotationOwner;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\Support\BuildsQuotationShareStorage;
use Tests\TestCase;

/** Real typed storage evidence; Action creation/replay is covered separately, never inferred from these fixtures. */
class QuotationNamedShareAccessTest extends TestCase
{
    use BuildsQuotationOwnership, BuildsQuotationShareStorage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
        foreach ([Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated([Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE]));
        $this->preparer->givePermissionTo(Duty::QUOTATION_ACCESS_DELEGATE);
        $this->colleague->givePermissionTo([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE]);
        foreach ([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE] as $duty) {
            AccessScopeLimit::create(['user_id' => $this->colleague->id, 'permission' => $duty, 'scope' => 'OWN_OR_DELEGATED']);
        }
    }

    public function test_named_view_reaches_only_its_family_and_prepare_remains_independent(): void
    {
        $family = $this->nativeFamily();
        $hidden = $this->nativeFamily();
        $this->rawGrant($family, $this->preparer, $this->colleague);
        Sanctum::actingAs($this->colleague);
        $revision = $family->revisions()->firstOrFail()->id;
        $this->getJson('/api/v1/quotations?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.family_id', $family->id);
        $this->getJson("/api/v1/quotations/{$revision}")->assertOk();
        $denied = $this->getJson("/api/v1/quotations/{$revision}/editable")->assertNotFound()->json();
        $this->assertSame($denied, $this->getJson('/api/v1/quotations/999999999/editable')->assertNotFound()->json());
        $this->getJson('/api/v1/quotations/'.$hidden->revisions()->firstOrFail()->id)->assertNotFound();
    }

    public function test_prepare_share_reaches_view_and_edit_but_never_management_without_nondelegated_view(): void
    {
        $family = $this->nativeFamily();
        $this->colleague->givePermissionTo(Duty::QUOTATION_ASSIGN);
        $this->rawGrant($family, $this->preparer, $this->colleague, ['level' => 'PREPARE']);
        Sanctum::actingAs($this->colleague);
        $this->getJson('/api/v1/quotations/'.$family->revisions()->firstOrFail()->id.'/editable')->assertOk();
        $this->assertSame([], DB::transaction(fn () => app(QuotationAccess::class)->queryFor($this->colleague, [Duty::QUOTATION_VIEW], false)->pluck('id')->all()));
        try {
            app(ReassignQuotationOwner::class)($this->colleague, $family->id, $this->colleague->id, 1, 1, $this->key(), 'Received share cannot manage access');
            $this->fail('Received delegation cannot supply management authority.');
        } catch (DomainInvariantException $failure) {
            $this->assertSame('The quotation was not found.', $failure->getMessage());
        }
        $this->assertSame(1, $family->fresh()->ownership_generation);
    }

    public function test_expired_future_revoked_and_wrong_recipient_grants_all_have_the_same_hidden_404(): void
    {
        $expired = $this->nativeFamily();
        $future = $this->nativeFamily();
        $revoked = $this->nativeFamily();
        $otherRecipient = $this->nativeFamily();
        $this->rawGrant($expired, $this->preparer, $this->colleague, ['starts_at' => DB::raw("statement_timestamp() - interval '2 hours'"), 'ends_at' => DB::raw("statement_timestamp() - interval '1 hour'")]);
        $this->rawGrant($future, $this->preparer, $this->colleague, ['starts_at' => DB::raw("statement_timestamp() + interval '1 hour'")]);
        $grant = $this->rawGrant($revoked, $this->preparer, $this->colleague);
        $this->rawRevoke($revoked, $grant, $this->assigner);
        $this->rawGrant($otherRecipient, $this->preparer, $this->viewOnly);
        Sanctum::actingAs($this->colleague);
        $unknown = $this->getJson('/api/v1/quotations/999999999')->assertNotFound()->json();
        foreach ([$expired, $future, $revoked, $otherRecipient] as $family) {
            $this->assertSame($unknown, $this->getJson('/api/v1/quotations/'.$family->revisions()->firstOrFail()->id)->assertNotFound()->json());
        }
        $this->getJson('/api/v1/quotations')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_a_share_never_widens_own_or_my_units_or_the_company_boundary(): void
    {
        $family = $this->nativeFamily();
        $this->legacyFamily();
        $unit = $this->unit();
        DB::table('organisation_unit_members')->insert(['company_id' => 1, 'unit_id' => $unit, 'user_id' => $this->colleague->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->rawGrant($family, $this->preparer, $this->colleague);
        $limit = AccessScopeLimit::where('user_id', $this->colleague->id)->where('permission', Duty::QUOTATION_VIEW)->firstOrFail();
        // Changing a scope invalidates old pins too; issue fresh evidence for each scope to prove explicit opt-in.
        foreach (['OWN', 'MY_UNITS'] as $scope) {
            $limit->update(['scope' => $scope]);
            $this->rawGrant($family, $this->preparer, $this->colleague);
            Sanctum::actingAs($this->colleague);
            $this->getJson('/api/v1/quotations/'.$family->revisions()->firstOrFail()->id)->assertNotFound();
        }
        $limit->update(['scope' => 'OWN_OR_DELEGATED']);
        $this->rawGrant($family, $this->preparer, $this->colleague);
        $foreign = $this->foreignFamilyId();
        $foreignRevision = DB::table('quotation_revisions')->insertGetId([
            'company_id' => $this->otherCompany, 'family_id' => $foreign,
            'currency_id' => $family->revisions()->firstOrFail()->currency_id,
            'created_by' => $this->otherUser, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(
            $this->getJson('/api/v1/quotations/999999999')->assertNotFound()->json(),
            $this->getJson('/api/v1/quotations/'.$foreignRevision)->assertNotFound()->json(),
        );
        $this->assertStorageRefuses(fn () => $this->rawGrant($family, $this->preparer, $this->colleague, ['company_id' => $this->otherCompany]));
        $this->assertSame([$family->id], DB::transaction(fn () => app(QuotationAccess::class)->queryFor($this->colleague, [Duty::QUOTATION_VIEW])->pluck('id')->all()));
        $this->assertFalse(DB::transaction(fn () => app(QuotationAccess::class)->queryFor($this->colleague, [Duty::QUOTATION_VIEW])->whereKey($foreign)->exists()));
        $limit->delete();
        $this->assertSame(2, DB::transaction(fn () => app(QuotationAccess::class)->queryFor($this->colleague, [Duty::QUOTATION_VIEW])->count()), 'Explicit ALL includes local unresolved records but never foreign records.');
    }

    public function test_permission_and_owner_round_trips_never_revive_old_grants(): void
    {
        $family = $this->nativeFamily();
        $this->rawGrant($family, $this->preparer, $this->colleague, ['level' => 'PREPARE']);
        $this->preparer->revokePermissionTo(Duty::QUOTATION_ACCESS_DELEGATE);
        $this->preparer->givePermissionTo(Duty::QUOTATION_ACCESS_DELEGATE);
        $this->assertSame([], DB::transaction(fn () => app(QuotationAccess::class)->queryFor($this->colleague, [Duty::QUOTATION_VIEW])->pluck('id')->all()));
        $this->rawGrant($family, $this->preparer, $this->colleague);
        app(ReassignQuotationOwner::class)($this->assigner, $family->id, $this->assigner->id, 1, 2, $this->key(), 'Move owner away');
        app(ReassignQuotationOwner::class)($this->assigner, $family->id, $this->preparer->id, 2, 2, $this->key(), 'Move owner back');
        $this->assertSame([], DB::transaction(fn () => app(QuotationAccess::class)->queryFor($this->colleague, [Duty::QUOTATION_VIEW])->pluck('id')->all()));
    }
}
