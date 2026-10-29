<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Sales\Actions\CaptureQuotationAccessBaseline;
use App\Modules\Sales\Actions\ChangeQuotationAccessUnit;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\ReassignQuotationOwner;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationOperationReceipt;
use App\Modules\Sales\Models\QuotationOwnershipEvent;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Quotation\QuotationOwnership;
use App\Modules\Sales\Support\Quotation\QuotationOwnershipOutcome;
use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

/**
 * QA1a — quotation ownership through the Actions (no route exists): the creator is the first owner with an INITIAL event, reassignment and the reserved access unit
 * are evidence-backed and generation-guarded, authority is two independent STAGED duties, and nothing is granted to anyone by being made an owner.
 */
class QuotationOwnershipTest extends TestCase
{
    use BuildsQuotationOwnership;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    private function reassign(QuotationFamily $family, int $ownerId, ?int $ownership = null, ?int $access = null, ?string $key = null, string $reason = 'Handover', ?User $by = null): QuotationOwnershipOutcome
    {
        $family->refresh();

        return app(ReassignQuotationOwner::class)($by ?? $this->assigner, $family->id, $ownerId, $ownership ?? $family->ownership_generation, $access ?? $family->access_generation, $key ?? $this->key(), $reason);
    }

    private function changeUnit(QuotationFamily $family, ?int $unitId, ?int $ownership = null, ?int $access = null, ?string $key = null, string $reason = 'Site move', ?User $by = null): QuotationOwnershipOutcome
    {
        $family->refresh();

        return app(ChangeQuotationAccessUnit::class)($by ?? $this->assigner, $family->id, $unitId, $ownership ?? $family->ownership_generation, $access ?? $family->access_generation, $key ?? $this->key(), $reason);
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

    private function snapshot(): array
    {
        return [DB::table('quotation_ownership_events')->count(), DB::table('sales_quotation_operation_receipts')->count(), DB::table('quotation_families')->orderBy('id')->get(['id', 'responsible_owner_id', 'access_unit_id', 'ownership_generation', 'access_generation', 'access_baseline_state'])->toArray()];
    }

    // ---- creation --------------------------------------------------------------------------------------------------------------------------------------

    public function test_the_creator_is_the_first_owner_with_an_initial_event_and_creator_provenance_is_kept(): void
    {
        $family = $this->nativeFamily();

        $this->assertSame($this->preparer->id, $family->created_by);
        $this->assertSame($this->preparer->id, $family->responsible_owner_id);
        $this->assertNull($family->access_unit_id);
        $this->assertSame([1, 0, 'NATIVE'], [$family->ownership_generation, $family->access_generation, $family->access_baseline_state]);
        $event = $family->ownershipEvents()->sole();
        $this->assertSame([QuotationOwnershipEvent::INITIAL, 1, null, null, $this->preparer->id, null, null, 0, $this->preparer->id], [$event->kind, $event->generation, $event->previous_event_id,
            $event->old_owner_id, $event->new_owner_id, $event->old_unit_id, $event->new_unit_id, $event->access_generation, $event->actor_id]);
        $this->assertNotSame('', trim($event->reason));
        $this->assertSame(1, DB::table('quotation_ownership_events')->count());
    }

    public function test_a_replayed_create_makes_no_second_family_or_event(): void
    {
        $key = $this->key();
        $input = ['recipient_party_id' => $this->prospect->id, 'pricing_date' => $this->today];
        $first = app(CreateQuotationDraft::class)($this->preparer, $key, $input);
        $again = app(CreateQuotationDraft::class)($this->preparer, $key, $input);

        $this->assertTrue($again->replayed);
        $this->assertSame($first->familyId, $again->familyId);
        $this->assertSame(1, QuotationFamily::count());
        $this->assertSame(1, DB::table('quotation_ownership_events')->count());
    }

    public function test_a_suspended_creator_cannot_start_a_quotation_and_nothing_is_left_behind(): void
    {
        $this->leaver->givePermissionTo([self::PREPARE]);
        $before = $this->snapshot();

        $this->assertSame('OWNER_NOT_ELIGIBLE', $this->refusal(fn () => app(CreateQuotationDraft::class)($this->leaver, $this->key(), ['recipient_party_id' => $this->prospect->id, 'pricing_date' => $this->today])));
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame(0, DB::table('quotation_families')->count());
    }

    // ---- authority -------------------------------------------------------------------------------------------------------------------------------------

    public function test_the_assign_duty_is_staged_so_every_ownership_action_refuses_everyone_including_raw_grants(): void
    {
        $this->app->forgetInstance(SalesDutyManifest::class);
        $this->app->bind(SalesDutyManifest::class, fn () => SalesDutyManifest::shipped());
        $family = $this->nativeFallback();

        foreach ([$this->assigner, $this->preparer] as $holder) {
            $this->assertFalse(SalesAccess::mayAll($holder, self::ASSIGN, self::VIEW));
        }
        $this->expectException(AuthorizationException::class);
        $this->reassign($family, $this->colleague->id);
    }

    /** Created under the shipped manifest is impossible (prepare is staged too), so the family is a raw NATIVE-shaped fixture. */
    private function nativeFallback(): QuotationFamily
    {
        $id = DB::table('quotation_families')->insertGetId(['company_id' => 1, 'created_by' => $this->preparer->id, 'responsible_owner_id' => $this->preparer->id, 'ownership_generation' => 1,
            'access_baseline_state' => 'NATIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('quotation_ownership_events')->insert(['company_id' => 1, 'family_id' => $id, 'generation' => 1, 'kind' => 'INITIAL', 'new_owner_id' => $this->preparer->id, 'access_generation' => 0,
            'actor_id' => $this->preparer->id, 'reason' => 'x', 'recorded_at' => now()]);

        return QuotationFamily::findOrFail($id);
    }

    public function test_assignment_needs_the_assign_duty_and_an_independent_view(): void
    {
        $family = $this->nativeFamily();
        $before = $this->snapshot();

        foreach ([$this->assignOnly, $this->viewOnly, $this->preparer, $this->colleague] as $holder) {
            try {
                $this->reassign($family, $this->colleague->id, by: $holder);
                $this->fail('Expected an authorisation refusal.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
            try {
                $this->changeUnit($family, $this->unit(), by: $holder);
                $this->fail('Expected an authorisation refusal.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame($this->colleague->id, $this->reassign($family, $this->colleague->id)->ownerId, 'assign + view together are enough');
    }

    public function test_capability_absence_is_refused_the_same_way_whether_or_not_the_family_exists(): void
    {
        $messages = [];
        foreach ([$this->nativeFamily()->id, 9999999, $this->foreignFamilyId()] as $familyId) {
            try {
                app(ReassignQuotationOwner::class)($this->viewOnly, $familyId, $this->colleague->id, 1, 0, $this->key(), 'x');
            } catch (AuthorizationException $e) {
                $messages[] = $e->getMessage();
            }
        }
        $this->assertCount(3, $messages);
        $this->assertCount(1, array_unique($messages));
        $this->expectException(AuthorizationException::class);
        QuotationOwnership::requireAuthority(null);
    }

    public function test_an_entitled_caller_gets_the_same_not_found_for_missing_and_foreign_families(): void
    {
        $codes = [
            $this->refusal(fn () => app(ReassignQuotationOwner::class)($this->assigner, 9999999, $this->colleague->id, 1, 0, $this->key(), 'x')),
            $this->refusal(fn () => app(ReassignQuotationOwner::class)($this->assigner, $this->foreignFamilyId(), $this->colleague->id, 1, 0, $this->key(), 'x')),
            $this->refusal(fn () => app(ChangeQuotationAccessUnit::class)($this->assigner, $this->foreignFamilyId(), null, 1, 0, $this->key(), 'x')),
        ];
        $this->assertSame(['QUOTATION_NOT_FOUND', 'QUOTATION_NOT_FOUND', 'QUOTATION_NOT_FOUND'], $codes);
    }

    public function test_a_suspended_or_stale_actor_cannot_change_ownership_and_nothing_is_written(): void
    {
        $family = $this->nativeFamily();
        $legacy = $this->legacyFamily();
        $before = $this->snapshot();

        // Holds both duties but is suspended: refused by every ownership Action before the family is looked up or a receipt considered.
        $this->leaver->givePermissionTo([self::ASSIGN, self::VIEW]);
        $calls = [
            fn ($actor) => app(ReassignQuotationOwner::class)($actor, $family->id, $this->colleague->id, 1, 0, $this->key(), 'x'),
            fn ($actor) => app(ChangeQuotationAccessUnit::class)($actor, $family->id, null, 1, 0, $this->key(), 'x'),
            fn ($actor) => app(CaptureQuotationAccessBaseline::class)($actor, $legacy->id, $this->colleague->id, null, 's', 'a', $this->key(), 'x'),
        ];
        foreach ($calls as $call) {
            try {
                $call($this->leaver);
                $this->fail('A suspended actor must be refused.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        // A hydrated actor who was active when loaded and is suspended afterwards: the object still says Active, the database does not.
        $stale = User::query()->findOrFail($this->assigner->id);
        $this->assertSame(MemberStatus::Active, $stale->status);
        DB::table('users')->where('id', $this->assigner->id)->update(['status' => 'SUSPENDED']);
        $this->assertSame(MemberStatus::Active, $stale->status, 'the hydrated object is stale');
        foreach ($calls as $call) {
            try {
                $call($stale);
                $this->fail('A stale actor suspended in the database must be refused.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertEquals($before, $this->snapshot(), 'no projection, event or receipt changed');

        // An actor from another company is refused as well, whatever they hold.
        $this->expectException(AuthorizationException::class);
        app(ReassignQuotationOwner::class)(User::query()->withoutGlobalScopes()->findOrFail($this->otherUser), $family->id, $this->colleague->id, 1, 0, $this->key(), 'x');
    }

    // ---- reassignment ----------------------------------------------------------------------------------------------------------------------------------

    public function test_reassignment_appends_evidence_keeps_provenance_and_grants_the_new_owner_nothing(): void
    {
        $family = $this->nativeFamily();
        $outcome = $this->reassign($family, $this->colleague->id, reason: 'Covering the account');

        $this->assertSame([$family->id, $this->colleague->id, null, 2, 0, true, false], [$outcome->familyId, $outcome->ownerId, $outcome->accessUnitId, $outcome->ownershipGeneration,
            $outcome->accessGeneration, $outcome->changed, $outcome->replayed]);
        $family->refresh();
        $this->assertSame($this->preparer->id, $family->created_by, 'created_by is provenance and never moves');
        $this->assertSame($this->colleague->id, $family->responsible_owner_id);
        $events = $family->ownershipEvents()->get();
        $this->assertCount(2, $events);
        $second = $events[1];
        $this->assertSame([QuotationOwnershipEvent::REASSIGNED, 2, $events[0]->id, $this->preparer->id, $this->colleague->id, $this->assigner->id, 'Covering the account'],
            [$second->kind, $second->generation, $second->previous_event_id, $second->old_owner_id, $second->new_owner_id, $second->actor_id, $second->reason]);
        $this->assertSame($events[0]->recorded_at->getTimestamp() <= $second->recorded_at->getTimestamp(), true);
        // Ownership is responsibility, not access.
        $this->assertFalse(SalesAccess::may($this->colleague, self::VIEW));
        $this->assertFalse(SalesAccess::may($this->colleague, self::ASSIGN));
        $this->assertFalse($this->colleague->can(self::VIEW));
    }

    public function test_naming_the_current_owner_changes_nothing_and_records_no_event(): void
    {
        $family = $this->nativeFamily();
        $before = DB::table('quotation_ownership_events')->count();
        $outcome = $this->reassign($family, $this->preparer->id);

        $this->assertFalse($outcome->changed);
        $this->assertSame(1, $outcome->ownershipGeneration);
        $this->assertSame($before, DB::table('quotation_ownership_events')->count());
        $this->assertSame(1, $family->refresh()->ownership_generation);
    }

    public function test_a_family_that_moved_is_stale_and_a_completed_request_replays_before_that_check(): void
    {
        $family = $this->nativeFamily();
        $key = $this->key();
        $first = $this->reassign($family, $this->colleague->id, key: $key);
        $this->reassign($family, $this->preparer->id);

        // Same key, same intent: the original result, although the family has since moved on.
        $replay = app(ReassignQuotationOwner::class)($this->assigner, $family->id, $this->colleague->id, 1, 0, $key, 'Handover');
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->ownershipGeneration, $replay->ownershipGeneration);
        $this->assertSame(3, $family->refresh()->ownership_generation);

        $this->assertSame('QUOTATION_OWNERSHIP_STALE', $this->refusal(fn () => $this->reassign($family, $this->colleague->id, ownership: 1)));
        $this->assertSame('QUOTATION_OWNERSHIP_STALE', $this->refusal(fn () => $this->reassign($family, $this->colleague->id, access: 5)));
        // A different intent under a used key is refused, not applied.
        $this->assertSame('REQUEST_KEY_REUSED', $this->refusal(fn () => app(ReassignQuotationOwner::class)($this->assigner, $family->id, $this->assigner->id, 1, 0, $key, 'Handover')));
        $this->assertSame(3, DB::table('quotation_ownership_events')->where('family_id', $family->id)->count());
    }

    public function test_a_target_who_is_not_an_active_member_of_this_company_is_refused_and_nothing_is_written(): void
    {
        $family = $this->nativeFamily();
        $before = $this->snapshot();

        foreach ([$this->leaver->id, $this->otherUser, 9999999] as $target) {
            $this->assertSame('OWNER_NOT_ELIGIBLE', $this->refusal(fn () => $this->reassign($family, $target)));
        }
        $this->assertEquals($before, $this->snapshot(), 'no event, projection or receipt on failure');

        // A previous owner who is later suspended stays valid history, but cannot be named again.
        $this->reassign($family, $this->colleague->id);
        $this->reassign($family, $this->assigner->id);
        $this->colleague->forceFill(['status' => MemberStatus::Suspended])->save();
        $this->assertSame($this->colleague->id, DB::table('quotation_ownership_events')->where('family_id', $family->id)->where('generation', 2)->value('new_owner_id'));
        $this->assertSame('OWNER_NOT_ELIGIBLE', $this->refusal(fn () => $this->reassign($family, $this->colleague->id)));
        $this->assertSame($this->assigner->id, $family->refresh()->responsible_owner_id);
    }

    public function test_a_reason_is_required(): void
    {
        $family = $this->nativeFamily();
        foreach (['', '   ', str_repeat('x', 1001)] as $reason) {
            $this->assertSame('REASON_REQUIRED', $this->refusal(fn () => $this->reassign($family, $this->colleague->id, reason: $reason)));
        }
        $this->assertSame(1, DB::table('quotation_ownership_events')->count());
    }

    public function test_a_legacy_family_with_no_recorded_owner_is_refused_by_the_ordinary_actions(): void
    {
        $legacy = $this->legacyFamily();
        $before = $this->snapshot();

        $this->assertSame('QUOTATION_OWNERSHIP_UNRESOLVED', $this->refusal(fn () => $this->reassign($legacy, $this->colleague->id, 0, 0)));
        $this->assertSame('QUOTATION_OWNERSHIP_UNRESOLVED', $this->refusal(fn () => $this->changeUnit($legacy, $this->unit(), 0, 0)));
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame(['UNRESOLVED', null, 0], [$legacy->refresh()->access_baseline_state, $legacy->responsible_owner_id, $legacy->ownership_generation], 'created_by was not copied into the owner');
        $this->assertSame($this->preparer->id, $legacy->created_by);
    }

    // ---- reserved access unit --------------------------------------------------------------------------------------------------------------------------

    public function test_the_reserved_unit_is_storage_and_evidence_only(): void
    {
        $family = $this->nativeFamily();
        $unit = $this->unit('SITE-A');
        $outcome = $this->changeUnit($family, $unit);

        $this->assertSame([$this->preparer->id, $unit, 2, 1, true], [$outcome->ownerId, $outcome->accessUnitId, $outcome->ownershipGeneration, $outcome->accessGeneration, $outcome->changed]);
        $event = $family->refresh()->ownershipEvents()->get()[1];
        $this->assertSame([QuotationOwnershipEvent::UNIT_CHANGED, null, $unit, $this->preparer->id, $this->preparer->id, 1], [$event->kind, $event->old_unit_id, $event->new_unit_id, $event->old_owner_id, $event->new_owner_id, $event->access_generation]);
        // An owner-only transfer keeps the unit; no unit is inferred from anyone's membership.
        $this->reassign($family, $this->colleague->id);
        $this->assertSame([$unit, $this->colleague->id, 3, 1], [$family->refresh()->access_unit_id, $family->responsible_owner_id, $family->ownership_generation, $family->access_generation]);
        // Clearing is an explicit change too; naming none when none is set is a no-op.
        $this->assertTrue($this->changeUnit($family, null)->changed);
        $this->assertNull($family->refresh()->access_unit_id);
        $this->assertFalse($this->changeUnit($family, null)->changed);
        $this->assertSame(4, DB::table('quotation_ownership_events')->where('family_id', $family->id)->count(), 'INITIAL, set, reassign, clear: the two no-ops recorded nothing');
    }

    public function test_only_an_active_unit_of_this_company_can_be_named(): void
    {
        $family = $this->nativeFamily();
        $before = $this->snapshot();

        foreach ([$this->unit('OLD', 'ARCHIVED'), $this->unit('THEIRS', 'ACTIVE', $this->otherCompany), 9999999] as $unit) {
            $this->assertSame('ACCESS_UNIT_NOT_FOUND', $this->refusal(fn () => $this->changeUnit($family, $unit)));
        }
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_a_unit_change_is_generation_guarded_and_replays(): void
    {
        $family = $this->nativeFamily();
        $unit = $this->unit();
        $key = $this->key();
        $first = $this->changeUnit($family, $unit, key: $key);
        $replay = app(ChangeQuotationAccessUnit::class)($this->assigner, $family->id, $unit, 1, 0, $key, 'Site move');

        $this->assertTrue($replay->replayed);
        $this->assertSame($first->accessGeneration, $replay->accessGeneration);
        $this->assertSame('QUOTATION_OWNERSHIP_STALE', $this->refusal(fn () => $this->changeUnit($family, null, ownership: 1, access: 0)));
        $this->assertSame(2, $family->refresh()->ownership_generation);
    }

    public function test_the_receipts_are_the_existing_quotation_receipts_and_no_route_exists(): void
    {
        $family = $this->nativeFamily();
        $this->reassign($family, $this->colleague->id);
        $receipt = QuotationOperationReceipt::query()->where('operation', 'quotation.reassign_owner')->sole();
        $this->assertSame($family->id, $receipt->family_id);
        $this->assertNull($receipt->revision_id);

        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertSame([], $uris->filter(fn ($u) => preg_match('#quotation.*(owner|ownership|assign|access|baseline)#', $u) === 1)->values()->all());
    }
}
