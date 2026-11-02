<?php

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        $this->assertThrows(fn () => $access->requireFamily($this->preparer, $legacy->id, [Duty::QUOTATION_VIEW]), ModelNotFoundException::class);
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
