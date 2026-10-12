<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\CorrectOpportunityAttribution;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\SalesHistory;
use App\Modules\Sales\Models\SalesStage;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** ADRs 0112 and 0114: correct who the record says was responsible on a CLOSED deal, append-only, without touching the deal. */
class OpportunityAttributionCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Party $party;

    private User $first;

    private User $second;

    private User $leaver;

    private int $dealId;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (Sales::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->party = Party::create(['code' => 'ABC', 'name' => 'ABC Industries']);
        SalesStage::create(['code' => 'QUALIFICATION', 'name' => 'Qualification', 'sort_order' => 10, 'status' => 'ACTIVE']);
        $this->first = $this->person([Sales::OPPORTUNITY_MANAGE]);
        $this->second = $this->person([Sales::OPPORTUNITY_MANAGE]);
        $this->leaver = $this->person([Sales::OPPORTUNITY_MANAGE]);
        $this->leaver->forceFill(['status' => MemberStatus::Suspended])->save();
        $this->dealId = $this->closedDeal();
    }

    /** A member of another company, inserted directly (the model refuses to create one outside the active company). */
    private function foreignMember(int $company, string $name): object
    {
        $id = DB::table('users')->insertGetId(['company_id' => $company, 'name' => $name, 'email' => uniqid().'@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        return (object) ['id' => $id];
    }

    /** @param list<string> $capabilities */
    private function person(array $capabilities): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($capabilities);

        return $user;
    }

    private function corrector(): User
    {
        return $this->person([Sales::PIPELINE_VIEW, Sales::OPPORTUNITY_ATTRIBUTION_CORRECT]);
    }

    /** A deal created for $first, reassigned to $second, then closed as lost. */
    private function closedDeal(): int
    {
        $manager = $this->person([Sales::PIPELINE_VIEW, Sales::OPPORTUNITY_MANAGE, Sales::OPPORTUNITY_ASSIGN, Sales::OPPORTUNITY_CLOSE]);
        $this->actingAs($manager);
        $id = (int) $this->postJson('/api/v1/opportunities', ['title' => 'Warehouse floor', 'party_id' => $this->party->id, 'owner_id' => $this->first->id])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/opportunities/{$id}/reassign", ['owner_id' => $this->second->id, 'reason' => 'Handover'])->assertOk();
        $this->postJson("/api/v1/opportunities/{$id}/close", ['outcome' => 'LOST', 'reason' => 'Price'])->assertOk();

        return $id;
    }

    private function event(string $kind, ?int $deal = null): SalesHistory
    {
        return SalesHistory::query()->where('opportunity_id', $deal ?? $this->dealId)->where('event', $kind)->orderBy('id')->firstOrFail();
    }

    /** @param array<string, mixed> $over */
    private function correct(array $over = [], ?int $deal = null)
    {
        $deal ??= $this->dealId;
        $body = array_merge([
            'target_event_id' => $this->event('OPPORTUNITY_CREATED', $deal)->id, 'target_field' => 'OWNER_ID', 'asserted_owner_id' => $this->second->id,
            'expected_predecessor_id' => null, 'expected_closure_event_id' => Opportunity::query()->findOrFail($deal)->latestClosureEventId(),
            'reason' => 'It was created for the wrong person', 'request_key' => 'attr-'.uniqid('', true),
        ], $over);

        return $this->postJson("/api/v1/opportunities/{$deal}/attribution-corrections", $body);
    }

    public function test_a_closed_deals_created_for_owner_is_corrected_without_touching_the_deal_or_its_events(): void
    {
        $this->actingAs($this->corrector());
        $deal = DB::table('sales_opportunities')->where('id', $this->dealId)->first();
        $events = DB::table('sales_events')->where('opportunity_id', $this->dealId)->orderBy('id')->get()->all();

        $response = $this->correct()->assertOk()->assertJsonPath('operation_result.changed', true)->assertJsonPath('replayed', false)
            ->assertJsonPath('data.owner_id', $this->second->id)->assertJsonPath('data.outcome', 'LOST');

        $this->assertEquals($deal, DB::table('sales_opportunities')->where('id', $this->dealId)->first(), 'the opportunity row is untouched, even updated_at');
        $this->assertEquals($events, array_slice(DB::table('sales_events')->where('opportunity_id', $this->dealId)->orderBy('id')->get()->all(), 0, count($events)), 'the source events are untouched');
        $row = DB::table('sales_opportunity_attribution_corrections')->sole();
        $this->assertSame([$this->first->id, $this->second->id, 'OWNER_ID', null], [$row->recorded_owner_id, $row->asserted_owner_id, $row->target_field, $row->predecessor_id]);
        $this->assertSame($row->id, $response->json('operation_result.correction_id'));
        $reference = $this->event('OPPORTUNITY_ATTRIBUTION_CORRECTED');
        $this->assertSame($row->id, $reference->details['correction_id']);
    }

    public function test_a_reassignment_destination_is_corrected_and_the_corrections_are_readable_as_a_chain(): void
    {
        $this->actingAs($this->corrector());
        $changed = $this->event('OWNER_CHANGED')->id;
        $first = $this->correct(['target_event_id' => $changed, 'target_field' => 'TO_OWNER_ID', 'asserted_owner_id' => $this->first->id])->assertOk()->json('operation_result.correction_id');
        $second = $this->correct(['target_event_id' => $changed, 'target_field' => 'TO_OWNER_ID', 'asserted_owner_id' => $this->leaver->id, 'expected_predecessor_id' => $first, 'request_key' => 'second'])
            ->assertOk()->json('operation_result.correction_id');

        $shown = $this->getJson("/api/v1/opportunities/{$this->dealId}?include=attribution_corrections")->assertOk();
        $this->assertSame([$first, $second], array_column($shown->json('data.attribution_corrections'), 'id'));
        $this->assertSame([$first, null], [$shown->json('data.attribution_corrections.1.predecessor_id'), $shown->json('data.attribution_corrections.0.predecessor_id')]);
        $this->assertSame($this->second->id, $shown->json('data.attribution_corrections.0.recorded_owner_id'), 'the recorded fact stays beside the correction');
        $this->assertTrue($shown->json('data.can_correct_attribution'));
        $this->assertNotNull($shown->json('data.closure_event_id'));
    }

    public function test_only_the_dedicated_duty_with_an_independent_read_right_may_correct(): void
    {
        $cases = [
            'every other sales duty' => [Sales::PIPELINE_VIEW, Sales::LEAD_MANAGE, Sales::OPPORTUNITY_MANAGE, Sales::OPPORTUNITY_ASSIGN, Sales::OPPORTUNITY_CLOSE, Sales::STAGE_MANAGE, Sales::NUMBERING_MANAGE],
            'the duty without any read right' => [Sales::OPPORTUNITY_ATTRIBUTION_CORRECT],
            'read only' => [Sales::PIPELINE_VIEW],
        ];
        foreach ($cases as $why => $capabilities) {
            $person = $this->person($capabilities);
            $this->actingAs($person);
            $this->correct()->assertForbidden();
            $this->getJson("/api/v1/opportunities/{$this->dealId}/attribution-members")->assertForbidden();
            $this->assertSame(0, DB::table('sales_opportunity_attribution_corrections')->count(), $why);
            try {
                app(CorrectOpportunityAttribution::class)(Opportunity::query()->findOrFail($this->dealId), $this->event('OPPORTUNITY_CREATED')->id, 'OWNER_ID', $this->second->id, null, null, 'r', 'k', $person);
                $this->fail("{$why} must not correct");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        // Owning the deal confers nothing.
        $this->actingAs($this->first)->postJson("/api/v1/opportunities/{$this->dealId}/attribution-corrections", [])->assertForbidden();
    }

    public function test_each_part_of_the_authority_is_judged_by_its_own_scope(): void
    {
        $mixed = function (array $restricted, array $unrestricted): User {
            $role = Role::create(['team_id' => 1, 'name' => 'Limited '.implode('+', $restricted).uniqid(), 'guard_name' => 'web']);
            $role->givePermissionTo($restricted);
            $user = User::factory()->create();
            $user->assignRole($role);
            AccessScopeLimit::create(['user_id' => $user->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS']);
            $user->givePermissionTo($unrestricted);

            return $user;
        };
        // A restricted correction duty cannot be rescued by an unrestricted read, and a restricted read cannot be rescued by an unrestricted duty.
        foreach ([$mixed([Sales::OPPORTUNITY_ATTRIBUTION_CORRECT], [Sales::PIPELINE_VIEW, Sales::STAGE_MANAGE]), $mixed([Sales::PIPELINE_VIEW], [Sales::OPPORTUNITY_ATTRIBUTION_CORRECT])] as $person) {
            $this->actingAs($person);
            $this->correct()->assertForbidden();
            $this->getJson("/api/v1/opportunities/{$this->dealId}/attribution-members")->assertForbidden();
        }
        $this->assertSame(0, DB::table('sales_opportunity_attribution_corrections')->count());

        // The same duty unrestricted through another grant wins.
        $this->actingAs($mixed([Sales::OPPORTUNITY_ATTRIBUTION_CORRECT], [Sales::PIPELINE_VIEW, Sales::OPPORTUNITY_ATTRIBUTION_CORRECT]));
        $this->correct()->assertOk();
    }

    public function test_only_a_recorded_owner_fact_of_this_deal_can_be_corrected(): void
    {
        $other = $this->closedDeal();
        $this->actingAs($this->corrector());
        $stage = SalesHistory::query()->create(['opportunity_id' => $this->dealId, 'event' => 'STAGE_CHANGED', 'actor_id' => $this->first->id, 'occurred_at' => now(), 'details' => []]);

        $this->correct(['target_event_id' => $this->event('OPPORTUNITY_CREATED', $other)->id])->assertUnprocessable()->assertJsonPath('reason_code', 'ATTRIBUTION_TARGET_UNSUPPORTED');
        $this->correct(['target_event_id' => $stage->id])->assertUnprocessable()->assertJsonPath('reason_code', 'ATTRIBUTION_TARGET_UNSUPPORTED');
        $this->correct(['target_event_id' => $this->event('OPPORTUNITY_CREATED')->id, 'target_field' => 'TO_OWNER_ID'])->assertUnprocessable()->assertJsonPath('reason_code', 'ATTRIBUTION_TARGET_UNSUPPORTED');
        $this->correct(['target_event_id' => $this->event('OWNER_CHANGED')->id, 'target_field' => 'OWNER_ID'])->assertUnprocessable()->assertJsonPath('reason_code', 'ATTRIBUTION_TARGET_UNSUPPORTED');
        $this->correct(['target_field' => 'FROM_OWNER_ID'])->assertUnprocessable()->assertJsonValidationErrors('target_field');
        $this->assertSame(0, DB::table('sales_opportunity_attribution_corrections')->count());
    }

    public function test_an_open_deal_is_refused_and_a_reopen_then_reclose_makes_an_earlier_confirmation_stale(): void
    {
        $this->actingAs($this->corrector());
        $marker = Opportunity::query()->findOrFail($this->dealId)->latestClosureEventId();
        $manager = $this->person([Sales::PIPELINE_VIEW, Sales::OPPORTUNITY_CLOSE]);

        $this->actingAs($manager)->postJson("/api/v1/opportunities/{$this->dealId}/reopen", ['reason' => 'Back'])->assertOk();
        $this->actingAs($this->corrector());
        $this->correct(['expected_closure_event_id' => $marker])->assertUnprocessable()->assertJsonPath('reason_code', 'ATTRIBUTION_REQUIRES_CLOSED');

        $this->actingAs($manager)->postJson("/api/v1/opportunities/{$this->dealId}/close", ['outcome' => 'ABANDONED', 'reason' => 'Gone'])->assertOk();
        $this->actingAs($this->corrector());
        $this->correct(['expected_closure_event_id' => $marker])->assertUnprocessable()->assertJsonPath('reason_code', 'STALE_EVIDENCE');
        $this->correct()->assertOk();
    }

    public function test_the_chain_keeps_one_head_a_stale_head_is_reviewed_and_an_identical_assertion_is_a_no_op(): void
    {
        $this->actingAs($this->corrector());
        $first = $this->correct()->assertOk()->json('operation_result.correction_id');

        $this->correct(['asserted_owner_id' => $this->leaver->id, 'expected_predecessor_id' => null])->assertUnprocessable()->assertJsonPath('reason_code', 'STALE_EVIDENCE');
        $this->correct(['asserted_owner_id' => $this->second->id, 'expected_predecessor_id' => $first, 'request_key' => 'same'])->assertOk()
            ->assertJsonPath('operation_result.changed', false)->assertJsonPath('operation_result.head_id', $first);
        $this->assertSame(1, DB::table('sales_opportunity_attribution_corrections')->count());
        // A later error is corrected by appending a successor, even back to the original; nothing is deleted or reset.
        $this->correct(['asserted_owner_id' => $this->first->id, 'expected_predecessor_id' => $first, 'request_key' => 'back'])->assertOk()->assertJsonPath('operation_result.changed', true);
        $this->assertSame(2, DB::table('sales_opportunity_attribution_corrections')->count());
        $this->assertSame(2, SalesHistory::query()->where('opportunity_id', $this->dealId)->where('event', 'OPPORTUNITY_ATTRIBUTION_CORRECTED')->count());
    }

    public function test_a_former_colleague_may_be_named_but_a_foreign_or_missing_identity_may_not(): void
    {
        $this->actingAs($this->corrector());
        $this->correct(['asserted_owner_id' => $this->leaver->id])->assertOk();

        $company = DB::table('companies')->insertGetId(['name' => 'Other Company', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = $this->foreignMember($company, 'Foreign Colleague');
        $this->correct(['asserted_owner_id' => $foreign->id, 'expected_predecessor_id' => DB::table('sales_opportunity_attribution_corrections')->value('id'), 'request_key' => 'foreign'])
            ->assertUnprocessable()->assertJsonPath('reason_code', 'OWNER_NOT_ELIGIBLE');
        $this->correct(['asserted_owner_id' => 999999, 'expected_predecessor_id' => DB::table('sales_opportunity_attribution_corrections')->value('id'), 'request_key' => 'missing'])
            ->assertUnprocessable()->assertJsonPath('reason_code', 'OWNER_NOT_ELIGIBLE');
    }

    public function test_the_same_key_replays_under_current_authority_and_a_different_intent_is_refused(): void
    {
        $corrector = $this->corrector();
        $this->actingAs($corrector);
        $body = ['request_key' => 'one'];
        $first = $this->correct($body)->assertOk()->json();
        $again = $this->correct($body)->assertOk()->json();
        $this->assertTrue($again['replayed']);
        $this->assertEquals($first['operation_result'], $again['operation_result']);
        $this->assertSame(1, DB::table('sales_opportunity_attribution_corrections')->count());
        $this->correct(['request_key' => 'one', 'asserted_owner_id' => $this->leaver->id])->assertUnprocessable()->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');

        // Replay is not a way around a revoked duty.
        $corrector->revokePermissionTo(Sales::OPPORTUNITY_ATTRIBUTION_CORRECT);
        $this->correct($body)->assertForbidden();
    }

    public function test_the_member_selector_is_narrow_and_company_scoped(): void
    {
        $this->actingAs($this->corrector());
        $members = $this->getJson("/api/v1/opportunities/{$this->dealId}/attribution-members?filter[search]=".urlencode($this->leaver->name))->assertOk()->json('data');
        $this->assertSame([['id' => $this->leaver->id, 'name' => $this->leaver->name, 'status' => 'SUSPENDED']], $members);
        $this->assertSame(['id', 'name', 'status'], array_keys($members[0]), 'no email or administration fields');

        $company = DB::table('companies')->insertGetId(['name' => 'Other Company', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = $this->foreignMember($company, 'Foreign Person');
        $this->assertSame([], $this->getJson('/api/v1/opportunities/'.$this->dealId.'/attribution-members?filter[search]=Foreign')->json('data'));
        $this->assertNotContains($foreign->id, array_column($this->getJson("/api/v1/opportunities/{$this->dealId}/attribution-members")->json('data'), 'id'));
    }

    public function test_the_database_refuses_raw_attacks_on_the_evidence(): void
    {
        $this->actingAs($this->corrector());
        $head = $this->correct()->assertOk()->json('operation_result.correction_id');
        $created = $this->event('OPPORTUNITY_CREATED')->id;
        $changed = $this->event('OWNER_CHANGED')->id;
        $other = $this->closedDeal();
        $otherCreated = $this->event('OPPORTUNITY_CREATED', $other)->id;
        $base = ['company_id' => 1, 'opportunity_id' => $this->dealId, 'target_event_id' => $created, 'target_field' => 'OWNER_ID', 'predecessor_id' => null,
            'recorded_owner_id' => $this->first->id, 'asserted_owner_id' => $this->leaver->id, 'actor_id' => $this->first->id, 'recorded_at' => now(), 'reason' => 'x'];
        $refuse = function (array $row, string $why) {
            DB::beginTransaction();
            try {
                DB::table('sales_opportunity_attribution_corrections')->insert($row);
                $this->fail('The database should have refused: '.$why);
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            } finally {
                DB::rollBack();
            }
        };

        $refuse($base, 'a second root for the same fact');
        $refuse(array_merge($base, ['target_event_id' => $changed, 'target_field' => 'TO_OWNER_ID', 'recorded_owner_id' => $this->second->id, 'predecessor_id' => $head]), 'a predecessor from another fact');
        $refuse(array_merge($base, ['target_event_id' => $otherCreated, 'recorded_owner_id' => $this->first->id]), "another deal's event");
        $refuse(array_merge($base, ['target_event_id' => $changed, 'target_field' => 'OWNER_ID', 'recorded_owner_id' => $this->second->id]), 'a field that does not match the event kind');
        $refuse(array_merge($base, ['target_event_id' => $changed, 'target_field' => 'TO_OWNER_ID', 'recorded_owner_id' => $this->leaver->id]), 'a recorded owner that is not the frozen source fact');
        $refuse(array_merge($base, ['target_event_id' => $changed, 'target_field' => 'TO_OWNER_ID', 'recorded_owner_id' => $this->second->id, 'reason' => '  ']), 'a blank reason');
        $refuse(array_merge($base, ['company_id' => 2]), 'a foreign company');
        $refuse(array_merge($base, ['target_event_id' => $changed, 'target_field' => 'TO_OWNER_ID', 'recorded_owner_id' => $this->second->id, 'asserted_owner_id' => $this->second->id]), 'a root that asserts what is already recorded');

        DB::beginTransaction();
        try {
            DB::table('sales_opportunity_attribution_corrections')->insert(array_merge($base, ['predecessor_id' => $head]));
            DB::table('sales_opportunity_attribution_corrections')->insert(array_merge($base, ['predecessor_id' => $head, 'asserted_owner_id' => $this->second->id]));
            $this->fail('A head has exactly one successor: no branch.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }

        DB::beginTransaction();
        try {
            DB::table('sales_opportunities')->where('id', $this->dealId)->update(['outcome' => 'OPEN', 'closed_at' => null, 'closed_by' => null, 'closure_reason' => null]);
            DB::table('sales_opportunity_attribution_corrections')->insert(array_merge($base, ['target_event_id' => $changed, 'target_field' => 'TO_OWNER_ID', 'recorded_owner_id' => $this->second->id]));
            $this->fail('A correction exists only on a closed opportunity.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
        foreach ([fn () => DB::table('sales_opportunity_attribution_corrections')->update(['reason' => 'rewrite']), fn () => DB::table('sales_opportunity_attribution_corrections')->delete()] as $attack) {
            DB::beginTransaction();
            try {
                $attack();
                $this->fail('Evidence is append-only.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            } finally {
                DB::rollBack();
            }
        }
    }
}
