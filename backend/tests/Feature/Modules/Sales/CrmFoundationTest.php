<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\SalesHistory;
use App\Modules\Sales\Models\SalesStage;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Phase 4 CRM foundation (ADRs 0098, 0105–0108): leads, opportunities, ownership, stages, outcomes and the history behind them. */
class CrmFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Party $party;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (Sales::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Permission::findOrCreate('parties.party.manage', 'web');
        $this->party = Party::create(['code' => 'ABC', 'name' => 'ABC Industries']);
        foreach ([['QUALIFICATION', 'Qualification', 10], ['PROPOSAL', 'Proposal', 20], ['NEGOTIATION', 'Negotiation', 30]] as [$code, $name, $order]) {
            SalesStage::create(['code' => $code, 'name' => $name, 'sort_order' => $order, 'status' => 'ACTIVE']);
        }
    }

    /** @param list<string> $capabilities */
    private function person(array $capabilities, ?string $status = null): User
    {
        $user = User::factory()->create($status === null ? [] : ['status' => $status]);
        $user->givePermissionTo($capabilities);

        return $user;
    }

    private function seller(): User
    {
        return $this->person([Sales::PIPELINE_VIEW, Sales::LEAD_MANAGE, Sales::OPPORTUNITY_MANAGE, Sales::OPPORTUNITY_ASSIGN, Sales::OPPORTUNITY_CLOSE, Sales::STAGE_MANAGE]);
    }

    /** @return array<string, mixed> */
    private function lead(array $over = []): array
    {
        return array_merge(['enquirer_name' => 'Fatima Khan', 'enquirer_organisation' => 'ABC Industries', 'enquirer_email' => 'fatima@abc.example', 'description' => 'Office furniture', 'source' => 'Referral'], $over);
    }

    public function test_an_enquiry_is_optional_numbered_unassigned_and_recorded_in_the_history(): void
    {
        $this->actingAs($this->seller());
        $lead = $this->postJson('/api/v1/leads', $this->lead())->assertCreated()->assertJsonPath('data.status', 'NEW')->assertJsonPath('data.assigned_to', null)->json('data');
        $this->assertStringStartsWith('LEAD-', $lead['number']);
        $this->assertSame(['LEAD_RECEIVED'], SalesHistory::query()->where('lead_id', $lead['id'])->pluck('event')->all());
        // No lead is needed for an opportunity.
        $this->postJson('/api/v1/opportunities', ['title' => 'Direct deal', 'party_id' => $this->party->id])->assertCreated()->assertJsonPath('data.outcome', 'OPEN')->assertJsonPath('data.lead_id', null);
    }

    public function test_qualify_disqualify_and_revive_keep_every_decision(): void
    {
        $this->actingAs($this->seller());
        $id = $this->postJson('/api/v1/leads', $this->lead())->json('data.id');

        $this->postJson("/api/v1/leads/{$id}/disqualify", [])->assertUnprocessable();
        $this->postJson("/api/v1/leads/{$id}/disqualify", ['reason' => 'Not our market'])->assertOk()->assertJsonPath('data.status', 'DISQUALIFIED');
        $this->postJson("/api/v1/leads/{$id}/qualify")->assertUnprocessable()->assertJsonPath('reason_code', 'LEAD_DISQUALIFIED');
        $this->postJson("/api/v1/leads/{$id}/revive", ['reason' => 'They came back'])->assertOk()->assertJsonPath('data.status', 'NEW');
        $this->postJson("/api/v1/leads/{$id}/qualify")->assertOk()->assertJsonPath('data.status', 'QUALIFIED');
        $this->postJson("/api/v1/leads/{$id}/qualify")->assertOk(); // idempotent

        $events = SalesHistory::query()->where('lead_id', $id)->orderBy('id')->get();
        $this->assertSame(['LEAD_RECEIVED', 'LEAD_DISQUALIFIED', 'LEAD_REVIVED', 'LEAD_QUALIFIED'], $events->pluck('event')->all());
        $this->assertSame('Not our market', $events[2]->details['disqualification_reason'], 'what was decided before is kept when the lead is revived');
    }

    public function test_the_enquiry_as_received_is_not_rewritten_after_a_decision(): void
    {
        $this->actingAs($this->seller());
        $id = $this->postJson('/api/v1/leads', $this->lead())->json('data.id');
        $this->patchJson("/api/v1/leads/{$id}", ['description' => 'Corrected'])->assertOk();
        $this->postJson("/api/v1/leads/{$id}/qualify")->assertOk();
        $this->patchJson("/api/v1/leads/{$id}", ['description' => 'Rewritten'])->assertUnprocessable()->assertJsonPath('reason_code', 'LEAD_NOT_NEW');
    }

    public function test_conversion_needs_an_identity_creates_no_account_and_is_safe_to_retry(): void
    {
        $this->actingAs($this->seller());
        $id = $this->postJson('/api/v1/leads', $this->lead())->json('data.id');
        $body = ['conversion_key' => 'k-1', 'title' => 'Furniture'];

        $this->postJson("/api/v1/leads/{$id}/convert", $body)->assertUnprocessable()->assertJsonPath('reason_code', 'PARTY_REQUIRED');
        $this->postJson("/api/v1/leads/{$id}/link-party", ['party_id' => $this->party->id])->assertOk()->assertJsonPath('data.party_id', $this->party->id);

        $first = $this->postJson("/api/v1/leads/{$id}/convert", $body)->assertCreated()->json('data');
        $again = $this->postJson("/api/v1/leads/{$id}/convert", $body)->assertOk()->json('data');
        $this->assertSame($first['id'], $again['id'], 'retrying one conversion intent creates nothing new');
        $second = $this->postJson("/api/v1/leads/{$id}/convert", ['conversion_key' => 'k-2', 'title' => 'Installation'])->assertCreated()->json('data');
        $this->assertNotSame($first['id'], $second['id'], 'one enquiry may yield several genuine deals');

        $this->assertSame(2, Opportunity::query()->where('lead_id', $id)->count());
        $this->assertSame(0, DB::table('customer_accounts')->count(), 'conversion never creates a customer account');
        $this->assertSame('QUALIFIED', DB::table('sales_leads')->where('id', $id)->value('status'));
        $this->postJson("/api/v1/leads/{$id}/disqualify", ['reason' => 'x'])->assertUnprocessable()->assertJsonPath('reason_code', 'LEAD_HAS_OPEN_OPPORTUNITY');
        $this->postJson("/api/v1/leads/{$id}/convert", ['title' => 'No key'])->assertUnprocessable();
    }

    public function test_an_owner_is_one_active_member_and_a_reassignment_is_audited_without_granting_anything(): void
    {
        $seller = $this->seller();
        $bilal = $this->person([Sales::PIPELINE_VIEW]);
        $suspended = $this->person([], 'SUSPENDED');
        $this->actingAs($seller);
        $id = $this->postJson('/api/v1/opportunities', ['title' => 'Deal', 'party_id' => $this->party->id])->assertCreated()->assertJsonPath('data.owner_id', $seller->id)->json('data.id');

        $this->postJson("/api/v1/opportunities/{$id}/reassign", ['owner_id' => $suspended->id])->assertUnprocessable()->assertJsonPath('reason_code', 'OWNER_NOT_ELIGIBLE');
        $this->postJson("/api/v1/opportunities/{$id}/reassign", ['owner_id' => $bilal->id, 'reason' => 'Aisha is leaving'])->assertOk()->assertJsonPath('data.owner_id', $bilal->id);
        $this->postJson("/api/v1/opportunities/{$id}/reassign", ['owner_id' => $bilal->id])->assertOk(); // same owner: nothing recorded

        $changes = SalesHistory::query()->where('opportunity_id', $id)->where('event', 'OWNER_CHANGED')->get();
        $this->assertCount(1, $changes);
        $this->assertSame([$seller->id, $bilal->id], [$changes[0]->details['from_owner_id'], $changes[0]->details['to_owner_id']]);
        $this->assertFalse($bilal->fresh()->can(Sales::OPPORTUNITY_MANAGE), 'responsibility is not authority');

        // Bilal can read the pipeline but may not manage it, and ownership did not change that.
        $this->actingAs($bilal)->getJson("/api/v1/opportunities/{$id}")->assertOk();
        $this->patchJson("/api/v1/opportunities/{$id}", ['title' => 'Mine now'])->assertForbidden();
    }

    public function test_an_owner_who_stops_being_eligible_is_flagged_not_silently_replaced(): void
    {
        $seller = $this->seller();
        $leaver = $this->person([Sales::OPPORTUNITY_MANAGE]);
        $this->actingAs($seller);
        $id = $this->postJson('/api/v1/opportunities', ['title' => 'Deal', 'party_id' => $this->party->id, 'owner_id' => $leaver->id])->assertCreated()->json('data.id');
        $leaver->forceFill(['status' => MemberStatus::Suspended])->save();

        $this->getJson("/api/v1/opportunities/{$id}")->assertOk()->assertJsonPath('data.owner_id', $leaver->id)->assertJsonPath('data.owner_needs_reassignment', true);
        $this->getJson('/api/v1/opportunities?filter[needs_attention]=true')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson("/api/v1/opportunities/{$id}/reassign", ['owner_id' => $seller->id])->assertOk()->assertJsonPath('data.owner_needs_reassignment', false);
        $this->assertSame($leaver->id, SalesHistory::query()->where('event', 'OWNER_CHANGED')->first()->details['from_owner_id'], 'the previous owner is preserved in the history');
    }

    public function test_assigning_someone_else_needs_the_assign_capability(): void
    {
        $manager = $this->person([Sales::OPPORTUNITY_MANAGE]);
        $other = $this->person([Sales::PIPELINE_VIEW]);
        $this->actingAs($manager);
        $this->postJson('/api/v1/opportunities', ['title' => 'Deal', 'party_id' => $this->party->id, 'owner_id' => $other->id])->assertForbidden();
        $this->postJson('/api/v1/opportunities', ['title' => 'Deal', 'party_id' => $this->party->id])->assertCreated();
        $this->postJson('/api/v1/leads', $this->lead(['assigned_to' => $other->id]))->assertForbidden();
    }

    public function test_stages_move_with_their_names_recorded_and_history_survives_a_rename_or_archive(): void
    {
        $this->actingAs($this->seller());
        $id = $this->postJson('/api/v1/opportunities', ['title' => 'Deal', 'party_id' => $this->party->id])->json('data.id');
        $proposal = SalesStage::query()->where('code', 'PROPOSAL')->firstOrFail();

        $this->postJson("/api/v1/opportunities/{$id}/stage", ['stage_id' => $proposal->id])->assertOk()->assertJsonPath('data.stage_id', $proposal->id);
        $this->patchJson("/api/v1/sales-stages/{$proposal->id}", ['name' => 'Quoting', 'status' => 'ARCHIVED'])->assertOk();
        $event = SalesHistory::query()->where('event', 'STAGE_CHANGED')->firstOrFail();
        $this->assertSame('Proposal', $event->details['to_stage_name'], 'history keeps the name the stage had then');

        $this->postJson("/api/v1/opportunities/{$id}/stage", ['stage_id' => SalesStage::query()->where('code', 'NEGOTIATION')->value('id')])->assertOk();
        $this->postJson("/api/v1/opportunities/{$id}/stage", ['stage_id' => $proposal->id])->assertUnprocessable()->assertJsonPath('reason_code', 'STAGE_NOT_AVAILABLE');
        $this->expectException(QueryException::class);
        DB::table('sales_stages')->where('id', $proposal->id)->delete();
    }

    public function test_the_last_active_stage_cannot_be_archived_and_suggested_stages_seed_once(): void
    {
        $this->actingAs($this->seller());
        $ids = SalesStage::query()->pluck('id');
        $this->patchJson("/api/v1/sales-stages/{$ids[0]}", ['status' => 'ARCHIVED'])->assertOk();
        $this->patchJson("/api/v1/sales-stages/{$ids[1]}", ['status' => 'ARCHIVED'])->assertOk();
        $this->patchJson("/api/v1/sales-stages/{$ids[2]}", ['status' => 'ARCHIVED'])->assertUnprocessable()->assertJsonPath('reason_code', 'LAST_ACTIVE_STAGE');

    }

    public function test_suggested_stages_are_offered_only_when_none_exist_and_never_twice(): void
    {
        DB::statement('ALTER TABLE sales_stages DISABLE TRIGGER sales_stage_never_deleted');
        SalesStage::query()->delete();
        DB::statement('ALTER TABLE sales_stages ENABLE TRIGGER sales_stage_never_deleted');
        $this->actingAs($this->seller());
        $this->getJson('/api/v1/opportunities')->assertOk();
        $this->postJson('/api/v1/opportunities', ['title' => 'Deal', 'party_id' => $this->party->id])->assertUnprocessable()->assertJsonPath('reason_code', 'NO_STAGES_CONFIGURED');
        $names = $this->postJson('/api/v1/sales-stages/seed-suggested')->assertOk()->json('data.*.name');
        $this->assertSame(['Qualification', 'Proposal', 'Negotiation'], $names);
        $this->postJson('/api/v1/sales-stages/seed-suggested')->assertOk();
        $this->assertSame(3, SalesStage::query()->count());
        $this->postJson('/api/v1/sales-stages', ['code' => 'proposal', 'name' => 'Again'])->assertUnprocessable()->assertJsonPath('reason_code', 'STAGE_CODE_IN_USE');
        $this->postJson('/api/v1/opportunities', ['title' => 'Deal', 'party_id' => $this->party->id])->assertCreated();
        $this->actingAs($this->person([Sales::OPPORTUNITY_MANAGE]))->postJson('/api/v1/sales-stages', ['code' => 'X', 'name' => 'X'])->assertForbidden();
    }

    public function test_closing_reopening_and_the_win_that_cannot_yet_happen(): void
    {
        $this->actingAs($this->seller());
        $id = $this->postJson('/api/v1/opportunities', ['title' => 'Deal', 'party_id' => $this->party->id])->json('data.id');

        $this->postJson("/api/v1/opportunities/{$id}/close", ['outcome' => 'WON', 'reason' => 'We won'])->assertUnprocessable()->assertJsonPath('reason_code', 'WON_REQUIRES_ACCEPTANCE');
        $this->postJson("/api/v1/opportunities/{$id}/close", ['outcome' => 'LOST'])->assertUnprocessable();
        $this->postJson("/api/v1/opportunities/{$id}/close", ['outcome' => 'LOST', 'reason' => 'Price'])->assertOk()->assertJsonPath('data.outcome', 'LOST')->assertJsonPath('data.closure_reason', 'Price');

        $this->patchJson("/api/v1/opportunities/{$id}", ['title' => 'Edit'])->assertUnprocessable()->assertJsonPath('reason_code', 'OPPORTUNITY_CLOSED');
        $this->postJson("/api/v1/opportunities/{$id}/stage", ['stage_id' => SalesStage::query()->value('id')])->assertUnprocessable();
        $this->postJson("/api/v1/opportunities/{$id}/notes", ['body' => 'Lessons learnt'])->assertCreated();

        $this->postJson("/api/v1/opportunities/{$id}/reopen", [])->assertUnprocessable();
        $this->postJson("/api/v1/opportunities/{$id}/reopen", ['reason' => 'Revived'])->assertOk()->assertJsonPath('data.outcome', 'OPEN')->assertJsonPath('data.closure_reason', null);
        $closure = SalesHistory::query()->where('event', 'REOPENED')->firstOrFail();
        $this->assertSame('LOST', $closure->details['previous_closure']['outcome'], 'the earlier closure is preserved');
        $this->assertSame(['OPPORTUNITY_CREATED', 'CLOSED', 'REOPENED'], SalesHistory::query()->where('opportunity_id', $id)->orderBy('id')->pluck('event')->all());
    }

    public function test_the_database_guards_closed_opportunities_history_and_outcomes(): void
    {
        $this->actingAs($this->seller());
        $id = $this->postJson('/api/v1/opportunities', ['title' => 'Deal', 'party_id' => $this->party->id])->json('data.id');
        $this->postJson("/api/v1/opportunities/{$id}/close", ['outcome' => 'ABANDONED', 'reason' => 'Gone quiet'])->assertOk();

        $this->assertRaises(fn () => DB::table('sales_opportunities')->where('id', $id)->update(['title' => 'Sneaky']));
        $this->assertRaises(fn () => DB::table('sales_opportunities')->where('id', $id)->delete());
        $this->assertRaises(fn () => DB::table('sales_events')->where('opportunity_id', $id)->update(['reason' => 'rewritten']));
        $this->assertRaises(fn () => DB::table('sales_events')->where('opportunity_id', $id)->delete());
        $this->assertRaises(fn () => DB::table('sales_opportunities')->where('id', $id)->update(['outcome' => 'OPEN']), 'reopening without clearing the closure is invalid');
        $this->assertRaises(fn () => DB::table('sales_opportunities')->where('id', $id)->update(['outcome' => 'FINISHED']));
    }

    public function test_an_unknown_value_stays_unknown_and_a_value_needs_its_currency(): void
    {
        $this->actingAs($this->seller());
        $pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        $unknown = $this->postJson('/api/v1/opportunities', ['title' => 'Unknown', 'party_id' => $this->party->id])->assertCreated()->json('data');
        $this->assertNull($unknown['expected_amount']);
        $this->postJson('/api/v1/opportunities', ['title' => 'Half', 'party_id' => $this->party->id, 'expected_amount' => '500'])->assertUnprocessable()->assertJsonPath('reason_code', 'EXPECTED_VALUE_INCOMPLETE');
        $known = $this->postJson('/api/v1/opportunities', ['title' => 'Known', 'party_id' => $this->party->id, 'expected_amount' => '500000', 'expected_currency_id' => $pkr->id])->assertCreated()->assertJsonPath('data.expected_amount', '500000.0000')->json('data.id');
        $this->patchJson("/api/v1/opportunities/{$known}", ['expected_amount' => null, 'expected_currency_id' => null])->assertOk()->assertJsonPath('data.expected_amount', null);
    }

    public function test_notes_next_action_and_expected_close_are_manual_and_authored(): void
    {
        $seller = $this->seller();
        $this->actingAs($seller);
        $id = $this->postJson('/api/v1/opportunities', ['title' => 'Deal', 'party_id' => $this->party->id, 'next_action' => 'Call back', 'next_action_on' => '2026-11-01', 'expected_close_on' => '2026-12-15'])->assertCreated()->assertJsonPath('data.next_action_on', '2026-11-01')->json('data.id');
        $this->postJson("/api/v1/opportunities/{$id}/notes", ['body' => 'Spoke to Fatima'])->assertCreated()->assertJsonPath('data.author_id', $seller->id);
        $this->postJson("/api/v1/opportunities/{$id}/notes", ['body' => '  '])->assertUnprocessable();
        $shown = $this->getJson("/api/v1/opportunities/{$id}?include=notes,history,party,stage")->assertOk()->json('data');
        $this->assertSame('Spoke to Fatima', $shown['notes'][0]['body']);
        $this->assertSame($seller->name, $shown['notes'][0]['author_name']);
        $this->assertSame('Qualification', $shown['stage']['name']);
        $this->assertNotEmpty($shown['history']);
    }

    public function test_the_people_who_can_be_made_responsible_are_active_members_with_only_id_and_name(): void
    {
        $seller = $this->seller();
        $this->person([], 'SUSPENDED')->forceFill(['name' => 'Suspended Sam'])->save();
        $this->person([])->forceFill(['name' => 'Active Amna'])->save();
        $this->actingAs($seller);
        $found = $this->getJson('/api/v1/sales-owners?filter[search]=Amna')->assertOk()->json('data');
        $this->assertSame(['id', 'name'], array_keys($found[0]));
        $this->assertSame([], $this->getJson('/api/v1/sales-owners?filter[search]=Sam')->json('data'));
        $this->actingAs($this->person([Sales::PIPELINE_VIEW]))->getJson('/api/v1/sales-owners')->assertForbidden();
    }

    public function test_the_contact_must_belong_to_the_party(): void
    {
        $this->actingAs($this->seller());
        $other = Party::create(['code' => 'OTHER', 'name' => 'Other']);
        $contact = DB::table('party_contacts')->insertGetId(['party_id' => $other->id, 'name' => 'Not ours', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/opportunities', ['title' => 'Deal', 'party_id' => $this->party->id, 'party_contact_id' => $contact])->assertUnprocessable()->assertJsonPath('reason_code', 'CONTACT_NOT_OF_PARTY');
    }

    public function test_every_surface_needs_a_capability_and_a_limited_person_is_refused_not_filtered(): void
    {
        $this->actingAs($this->person([]));
        foreach (['leads', 'opportunities', 'sales-stages'] as $path) {
            $this->getJson("/api/v1/{$path}")->assertForbidden();
        }
        $this->postJson('/api/v1/leads', $this->lead())->assertForbidden();

        $role = Role::create(['team_id' => 1, 'name' => 'Limited seller', 'guard_name' => 'web']);
        $role->givePermissionTo([Sales::PIPELINE_VIEW, Sales::OPPORTUNITY_MANAGE, Sales::LEAD_MANAGE]);
        $limited = User::factory()->create();
        $limited->assignRole($role);
        AccessScopeLimit::create(['user_id' => $limited->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS']);
        $this->actingAs($limited);
        $this->getJson('/api/v1/opportunities')->assertForbidden();
        $this->getJson('/api/v1/leads')->assertForbidden();
        $this->postJson('/api/v1/opportunities', ['title' => 'x', 'party_id' => $this->party->id])->assertForbidden();
        $this->assertSame(0, Opportunity::query()->count());
    }

    public function test_another_companys_records_are_unreachable_by_id(): void
    {
        $seller = $this->seller();
        $this->actingAs($seller);
        $id = $this->postJson('/api/v1/opportunities', ['title' => 'Ours', 'party_id' => $this->party->id])->json('data.id');
        $lead = $this->postJson('/api/v1/leads', $this->lead())->json('data.id');

        $company = DB::table('companies')->insertGetId(['name' => 'Other Company', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $this->getJson("/api/v1/opportunities/{$id}")->assertNotFound();
        $this->postJson("/api/v1/opportunities/{$id}/close", ['outcome' => 'LOST', 'reason' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/leads/{$lead}/qualify")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/opportunities')->json('data'));
    }

    private function assertRaises(\Closure $statement, string $message = ''): void
    {
        try {
            DB::beginTransaction();
            $statement();
            $this->fail($message ?: 'The database should have refused this change.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }
}
