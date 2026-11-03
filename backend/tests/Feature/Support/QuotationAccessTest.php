<?php

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

class QuotationAccessTest extends TestCase
{
    use BuildsQuotationOwnership, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    public function test_own_follows_responsibility_and_all_includes_unresolved(): void
    {
        $owned = $this->nativeFamily();
        $legacy = $this->legacyFamily();
        AccessScopeLimit::create(['user_id' => $this->preparer->id, 'permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN']);
        $access = app(QuotationAccess::class);
        $this->assertSame([$owned->id], $access->queryFor($this->preparer, [Duty::QUOTATION_VIEW])->pluck('quotation_families.id')->all());
        $this->assertEqualsCanonicalizing([$owned->id, $legacy->id], $access->queryFor($this->assigner, [Duty::QUOTATION_VIEW])->pluck('quotation_families.id')->all());
        $this->assertFalse($access->queryFor($this->assigner, [Duty::QUOTATION_VIEW])->whereKey($this->foreignFamilyId())->exists());
        $this->assertThrows(fn () => $access->requireFamily($this->preparer, $legacy->id, [Duty::QUOTATION_VIEW]), DomainInvariantException::class);
    }

    public function test_revision_loader_rejects_hidden_and_unknown_identically(): void
    {
        $own = $this->nativeFamily();
        $other = User::factory()->create();
        $other->givePermissionTo([Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW]);
        $hidden = $this->nativeFamily($other);
        AccessScopeLimit::create(['user_id' => $this->preparer->id, 'permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN']);
        $access = app(QuotationAccess::class);
        $ownRevision = $own->revisions()->firstOrFail()->id;
        $hiddenRevision = $hidden->revisions()->firstOrFail()->id;
        $this->assertSame($ownRevision, $access->requireRevision($this->preparer, $ownRevision, [Duty::QUOTATION_VIEW], true)->id);
        $this->assertThrows(fn () => $access->requireRevision($this->preparer, $hiddenRevision, [Duty::QUOTATION_VIEW]), DomainInvariantException::class);
        $this->assertThrows(fn () => $access->requireRevision($this->preparer, 9999999, [Duty::QUOTATION_VIEW]), DomainInvariantException::class);
    }

    public function test_family_loader_has_one_non_disclosing_refusal_for_hidden_foreign_and_unknown(): void
    {
        $legacy = $this->legacyFamily();
        $foreign = $this->foreignFamilyId();
        AccessScopeLimit::create(['user_id' => $this->preparer->id, 'permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN']);
        foreach ([$legacy->id, $foreign, 99999999] as $id) {
            try {
                app(QuotationAccess::class)->requireFamily($this->preparer, $id, [Duty::QUOTATION_VIEW]);
                $this->fail('An invisible family must be refused.');
            } catch (DomainInvariantException $exception) {
                $this->assertSame(['QUOTATION_NOT_FOUND', 'family_id', 'The quotation was not found.'], [$exception->reasonCode, $exception->field, $exception->getMessage()]);
            }
        }
    }

    public function test_each_required_duty_is_independently_intersected(): void
    {
        $family = $this->nativeFamily();
        AccessScopeLimit::create(['user_id' => $this->preparer->id, 'permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN']);
        $this->assertSame($family->id, app(QuotationAccess::class)->requireFamily($this->preparer, $family->id, [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE])->id);
        $this->preparer->revokePermissionTo(Duty::QUOTATION_PREPARE);
        $this->assertThrows(fn () => app(QuotationAccess::class)->requireFamily($this->preparer, $family->id, [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE]), AuthorizationException::class);
    }

    public function test_staged_duty_and_revoked_hydrated_actor_do_not_authorise(): void
    {
        $this->nativeFamily();
        $stale = clone $this->preparer->load('permissions');
        $this->preparer->revokePermissionTo(Duty::QUOTATION_VIEW);
        $this->assertThrows(fn () => app(QuotationAccess::class)->queryFor($stale, [Duty::QUOTATION_VIEW]), AuthorizationException::class);
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->assertThrows(fn () => app(QuotationAccess::class)->queryFor($this->assigner, [Duty::QUOTATION_VIEW]), AuthorizationException::class);
    }

    public function test_unrelated_permission_does_not_create_quotation_authority(): void
    {
        $user = User::factory()->create();
        $this->assertThrows(fn () => app(QuotationAccess::class)->queryFor($user, [Duty::QUOTATION_VIEW]), AuthorizationException::class);
    }
}
