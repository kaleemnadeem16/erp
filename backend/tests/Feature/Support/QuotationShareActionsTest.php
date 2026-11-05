<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\Access\GrantQuotationAccess;
use App\Modules\Sales\Actions\Access\RevokeQuotationAccess;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

class QuotationShareActionsTest extends TestCase
{
    use BuildsQuotationOwnership, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
        foreach ([Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated([Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE]));
        $this->preparer->givePermissionTo([Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE]);
        $this->colleague->givePermissionTo([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE]);
        foreach ([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE] as $duty) {
            AccessScopeLimit::create(['user_id' => $this->colleague->id, 'permission' => $duty, 'scope' => 'OWN_OR_DELEGATED']);
        }
    }

    public function test_real_grant_and_revoke_actions_append_once_and_replay_before_staleness(): void
    {
        $family = $this->nativeFamily();
        $grant = app(GrantQuotationAccess::class);
        $key = $this->key();
        $first = $grant($this->preparer, $family->id, $this->colleague->id, 'PREPARE', 1, 0, $key, 'A real Action grant');
        $this->assertTrue($first->changed);
        $this->assertFalse($first->replayed);
        $this->assertSame([1, 1], [$first->ownershipGeneration, $first->accessGeneration]);
        $again = $grant($this->preparer, $family->id, $this->colleague->id, 'PREPARE', 1, 0, $key, 'A real Action grant');
        $this->assertTrue($again->replayed);
        $this->assertSame($first->grantId, $again->grantId);
        $this->assertSame(1, DB::table('quotation_access_grants')->count());
        $this->assertSame(1, DB::table('quotation_access_events')->count());
        Sanctum::actingAs($this->colleague);
        $this->getJson('/api/v1/quotations/'.$family->revisions()->firstOrFail()->id.'/editable')->assertOk();
        $revoke = app(RevokeQuotationAccess::class);
        $key = $this->key();
        $revoked = $revoke($this->preparer, $family->id, $first->grantId, 1, 1, $key, 'A real Action revocation');
        $this->assertSame(2, $revoked->accessGeneration);
        $this->assertTrue($revoke($this->preparer, $family->id, $first->grantId, 1, 1, $key, 'A real Action revocation')->replayed);
        $this->assertSame(1, DB::table('quotation_access_revocations')->count());
        $this->assertSame(2, DB::table('quotation_access_events')->count());
        $this->getJson('/api/v1/quotations/'.$family->revisions()->firstOrFail()->id)->assertNotFound();
        $this->assertFalse($revoke($this->preparer, $family->id, $first->grantId, 1, 2, $this->key(), 'Already revoked')->changed);
        $this->assertSame(2, $family->fresh()->access_generation);
    }

    public function test_own_only_self_foreign_and_inactive_recipients_are_refused_without_receipts_or_evidence(): void
    {
        $family = $this->nativeFamily();
        AccessScopeLimit::where('user_id', $this->colleague->id)->update(['scope' => 'OWN']);
        $receipts = DB::table('sales_quotation_operation_receipts')->count();
        foreach ([$this->colleague->id, $this->preparer->id, $this->otherUser, $this->leaver->id, 999999999] as $recipient) {
            try {
                app(GrantQuotationAccess::class)($this->preparer, $family->id, $recipient, 'VIEW', 1, 0, $this->key(), 'Refused recipient');
                $this->fail('An ineffective or foreign recipient must refuse.');
            } catch (DomainInvariantException $failure) {
                $this->assertSame('That recipient is not eligible for this quotation access.', $failure->getMessage());
            }
        }
        $this->assertSame($receipts, DB::table('sales_quotation_operation_receipts')->count());
        $this->assertSame(0, DB::table('quotation_access_grants')->count());
        $this->assertSame(0, $family->fresh()->access_generation);
    }

    public function test_overlapping_schedules_refuse_and_expired_grants_can_be_revoked(): void
    {
        $family = $this->nativeFamily();
        $grant = app(GrantQuotationAccess::class);
        $first = $grant($this->preparer, $family->id, $this->colleague->id, 'VIEW', 1, 0, $this->key(), 'Expired interval', '2020-01-01T00:00:00Z', '2020-01-02T00:00:00Z');
        try {
            $grant($this->preparer, $family->id, $this->colleague->id, 'VIEW', 1, 1, $this->key(), 'Overlapping interval', '2020-01-01T12:00:00Z', '2020-01-02T12:00:00Z');
            $this->fail('An epoch-valid interval overlap is refused even after expiry.');
        } catch (DomainInvariantException $failure) {
            $this->assertStringContainsString('already overlaps', $failure->getMessage());
        }
        $this->assertTrue(app(RevokeQuotationAccess::class)($this->preparer, $family->id, $first->grantId, 1, 1, $this->key(), 'Revoke after expiry')->changed);
        $this->assertSame(2, $family->fresh()->access_generation);
    }

    public function test_unrestricted_recipient_is_a_noop_and_authority_loss_refuses_receipt_replay(): void
    {
        $family = $this->nativeFamily();
        AccessScopeLimit::where('user_id', $this->colleague->id)->delete();
        $key = $this->key();
        $grant = app(GrantQuotationAccess::class);
        $this->assertFalse($grant($this->preparer, $family->id, $this->colleague->id, 'VIEW', 1, 0, $key, 'Already eligible')->changed);
        $this->assertSame(0, DB::table('quotation_access_grants')->count());
        $this->preparer->revokePermissionTo(Duty::QUOTATION_ACCESS_DELEGATE);
        $this->expectException(AuthorizationException::class);
        $grant($this->preparer, $family->id, $this->colleague->id, 'VIEW', 1, 0, $key, 'Already eligible');
    }

    public function test_staged_registry_refuses_actions_even_with_raw_permission_rows(): void
    {
        $family = $this->nativeFamily();
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->expectException(AuthorizationException::class);
        app(GrantQuotationAccess::class)($this->preparer, $family->id, $this->colleague->id, 'VIEW', 1, 0, $this->key(), 'Staged must refuse');
    }

    public function test_received_access_cannot_be_delegated_or_revoked_onward(): void
    {
        $family = $this->nativeFamily();
        $this->colleague->givePermissionTo([Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE]);
        AccessScopeLimit::create(['user_id' => $this->viewOnly->id, 'permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN_OR_DELEGATED']);
        $grant = app(GrantQuotationAccess::class)($this->preparer, $family->id, $this->colleague->id, 'VIEW', 1, 0, $this->key(), 'First-hop share');
        foreach ([
            fn () => app(GrantQuotationAccess::class)($this->colleague, $family->id, $this->viewOnly->id, 'VIEW', 1, 1, $this->key(), 'Refused onward grant'),
            fn () => app(RevokeQuotationAccess::class)($this->colleague, $family->id, $grant->grantId, 1, 1, $this->key(), 'Refused onward revocation'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Received access cannot supply non-delegated management eligibility.');
            } catch (DomainInvariantException $failure) {
                $this->assertSame('The quotation was not found.', $failure->getMessage());
            }
        }
        $this->assertSame(1, DB::table('quotation_access_grants')->count());
        $this->assertSame(0, DB::table('quotation_access_revocations')->count());
    }

    public function test_view_and_prepare_capabilities_remain_independent_for_both_participants(): void
    {
        $family = $this->nativeFamily();
        $this->colleague->revokePermissionTo(Duty::QUOTATION_PREPARE);
        try {
            app(GrantQuotationAccess::class)($this->preparer, $family->id, $this->colleague->id, 'PREPARE', 1, 0, $this->key(), 'Recipient lacks prepare');
            $this->fail('A PREPARE share cannot create a missing capability.');
        } catch (DomainInvariantException $failure) {
            $this->assertSame('That recipient is not eligible for this quotation access.', $failure->getMessage());
        }
        $this->preparer->revokePermissionTo(Duty::QUOTATION_VIEW);
        $this->expectException(AuthorizationException::class);
        app(GrantQuotationAccess::class)($this->preparer, $family->id, $this->colleague->id, 'VIEW', 1, 0, $this->key(), 'Issuer lacks view');
    }

    public function test_management_outside_own_scope_is_hidden_and_records_nothing(): void
    {
        $family = $this->nativeFamily($this->colleague);
        AccessScopeLimit::create(['user_id' => $this->preparer->id, 'permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN']);
        $before = DB::table('sales_quotation_operation_receipts')->count();
        foreach ([$family->id, 999999999, $this->foreignFamilyId()] as $id) {
            try {
                app(GrantQuotationAccess::class)($this->preparer, $id, $this->viewOnly->id, 'VIEW', 1, 0, $this->key(), 'Hidden family');
                $this->fail('Management must intersect its independent view scope.');
            } catch (DomainInvariantException $failure) {
                $this->assertSame('The quotation was not found.', $failure->getMessage());
            }
        }
        $this->assertSame($before, DB::table('sales_quotation_operation_receipts')->count());
        $this->assertSame(0, DB::table('quotation_access_grants')->count());
    }
}
