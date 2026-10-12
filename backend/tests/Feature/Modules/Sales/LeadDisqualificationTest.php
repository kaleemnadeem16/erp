<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\DecideLead;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\SalesHistory;
use App\Modules\Sales\Models\SalesStage;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** ADR 0111: an enquiry is disqualified on its own merits; deals already derived from it stay open, untouched and unmerged. */
class LeadDisqualificationTest extends TestCase
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
        $this->party = Party::create(['code' => 'ABC', 'name' => 'ABC Industries']);
        SalesStage::create(['code' => 'QUALIFICATION', 'name' => 'Qualification', 'sort_order' => 10, 'status' => 'ACTIVE']);
    }

    private function seller(array $capabilities = [Sales::PIPELINE_VIEW, Sales::LEAD_MANAGE, Sales::OPPORTUNITY_MANAGE]): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($capabilities);

        return $user;
    }

    private function lead(): int
    {
        return (int) $this->postJson('/api/v1/leads', ['enquirer_name' => 'Fatima Khan', 'description' => 'Office furniture', 'source' => 'Referral', 'request_key' => 'lead-'.uniqid('', true)])->assertCreated()->json('data.id');
    }

    /** @param array<string, mixed> $over */
    private function disqualify(int $id, array $over = [])
    {
        $marker = array_key_exists('expected_decision_event_id', $over) ? $over['expected_decision_event_id'] : $this->getJson("/api/v1/leads/{$id}")->json('data.decision_event_id');

        return $this->postJson("/api/v1/leads/{$id}/disqualify", array_merge(['reason' => 'Duplicate intake', 'request_key' => 'dq-'.$id, 'expected_decision_event_id' => $marker], $over));
    }

    private function convert(int $id, string $key): void
    {
        $this->postJson("/api/v1/leads/{$id}/convert", ['conversion_key' => $key, 'title' => "Deal {$key}", 'party_id' => $this->party->id])->assertCreated();
    }

    public function test_a_duplicate_intake_is_disqualified_while_its_two_genuine_deals_stay_open(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $this->convert($id, 'a');
        $this->convert($id, 'b');
        $deals = Opportunity::query()->where('lead_id', $id)->orderBy('id')->get()->map->getAttributes()->all();
        $partyBefore = Party::query()->findOrFail($this->party->id)->getAttributes();

        $response = $this->disqualify($id)->assertOk()->assertJsonPath('data.status', 'DISQUALIFIED')
            ->assertJsonPath('operation_result.changed', true)->assertJsonPath('replayed', false);

        $this->assertSame($deals, Opportunity::query()->where('lead_id', $id)->orderBy('id')->get()->map->getAttributes()->all(), 'no deal is changed, merged or closed');
        $this->assertSame($partyBefore, Party::query()->findOrFail($this->party->id)->getAttributes());
        $event = SalesHistory::query()->where('lead_id', $id)->where('event', 'LEAD_DISQUALIFIED')->sole();
        $this->assertSame('Duplicate intake', $event->reason);
        $this->assertSame('QUALIFIED', $event->details['prior_status'], 'the prior qualification is recorded before the projection is cleared');
        $this->assertNotNull($event->details['prior_qualified_at']);
        $this->assertSame($event->id, $response->json('operation_result.decision_event_id'));
        $this->assertSame(2, SalesHistory::query()->where('lead_id', $id)->where('event', 'LEAD_CONVERTED')->count());
    }

    public function test_a_new_enquiry_without_deals_is_disqualified_and_a_reason_is_required(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();

        $this->disqualify($id, ['reason' => '  '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/leads/{$id}/disqualify", ['reason' => 'x', 'expected_decision_event_id' => null])->assertUnprocessable();
        $this->assertSame('NEW', Lead::query()->findOrFail($id)->status->value);
        $this->disqualify($id)->assertOk()->assertJsonPath('data.status', 'DISQUALIFIED');
    }

    public function test_the_same_key_and_intent_replays_the_original_result_even_after_later_changes(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $first = $this->disqualify($id)->assertOk()->json();
        $this->postJson("/api/v1/leads/{$id}/revive", ['reason' => 'They came back'])->assertOk();

        $again = $this->postJson("/api/v1/leads/{$id}/disqualify", ['reason' => 'Duplicate intake', 'request_key' => 'dq-'.$id, 'expected_decision_event_id' => null])->assertOk()->json();

        $this->assertTrue($again['replayed']);
        $this->assertEquals($first['operation_result'], $again['operation_result'], 'the original result is replayed unchanged');
        $this->assertSame('NEW', $again['data']['status'], 'the current projection is not mislabelled as the operation result');
        $this->assertSame(1, SalesHistory::query()->where('lead_id', $id)->where('event', 'LEAD_DISQUALIFIED')->count(), 'a replay mutates nothing');
    }

    public function test_the_same_key_for_a_different_intent_is_refused(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $this->disqualify($id)->assertOk();

        $this->disqualify($id, ['reason' => 'A different reason'])->assertUnprocessable()->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
        $other = $this->lead();
        $this->disqualify($other, ['request_key' => 'dq-'.$id])->assertUnprocessable()->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
        $this->assertSame('NEW', Lead::query()->findOrFail($other)->status->value);
    }

    public function test_a_stale_decision_marker_is_reviewed_not_overwritten(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $seen = $this->getJson("/api/v1/leads/{$id}")->json('data.decision_event_id');
        $this->assertNull($seen);

        // Conversion qualifies the enquiry after the person opened it.
        $this->convert($id, 'a');
        $this->disqualify($id, ['expected_decision_event_id' => $seen, 'request_key' => 'stale'])->assertUnprocessable()->assertJsonPath('reason_code', 'STALE_EVIDENCE');
        $this->assertSame('QUALIFIED', Lead::query()->findOrFail($id)->status->value);

        // After refreshing, the same decision is valid.
        $this->disqualify($id, ['request_key' => 'fresh'])->assertOk();

        // A revival that happened after a screen was opened is not overwritten by a stale confirmation either.
        $marker = $this->getJson("/api/v1/leads/{$id}")->json('data.decision_event_id');
        $this->postJson("/api/v1/leads/{$id}/revive", ['reason' => 'Back'])->assertOk();
        $this->disqualify($id, ['expected_decision_event_id' => $marker, 'request_key' => 'late'])->assertUnprocessable()->assertJsonPath('reason_code', 'STALE_EVIDENCE');
        $this->assertSame('NEW', Lead::query()->findOrFail($id)->status->value);
    }

    public function test_an_already_disqualified_enquiry_is_a_recorded_no_op(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $this->disqualify($id)->assertOk();
        $marker = $this->getJson("/api/v1/leads/{$id}")->json('data.decision_event_id');

        $this->postJson("/api/v1/leads/{$id}/disqualify", ['reason' => 'Again', 'request_key' => 'dq-again', 'expected_decision_event_id' => $marker])
            ->assertOk()->assertJsonPath('operation_result.changed', false);
        $this->assertSame(1, SalesHistory::query()->where('lead_id', $id)->where('event', 'LEAD_DISQUALIFIED')->count());
        $this->assertSame(1, DB::table('sales_crm_operation_receipts')->where('request_key', 'dq-again')->count(), 'the no-op still has its retry receipt');
    }

    public function test_conversion_after_disqualification_is_refused_but_a_completed_conversion_replays(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $this->convert($id, 'a');
        $this->disqualify($id)->assertOk();

        $this->postJson("/api/v1/leads/{$id}/convert", ['conversion_key' => 'new', 'title' => 'Another', 'party_id' => $this->party->id])
            ->assertUnprocessable()->assertJsonPath('reason_code', 'LEAD_DISQUALIFIED');
        $this->postJson("/api/v1/leads/{$id}/convert", ['conversion_key' => 'a', 'title' => 'Deal a', 'party_id' => $this->party->id])->assertOk();
        $this->assertSame(1, Opportunity::query()->where('lead_id', $id)->count());
    }

    public function test_it_needs_lead_manage_and_its_own_scope_through_http_and_the_action(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $viewer = $this->seller([Sales::PIPELINE_VIEW, Sales::OPPORTUNITY_MANAGE]);

        $this->actingAs($viewer);
        $this->disqualify($id, ['expected_decision_event_id' => null])->assertForbidden();
        $this->assertSame('NEW', Lead::query()->findOrFail($id)->status->value);
        $this->assertSame(0, DB::table('sales_crm_operation_receipts')->where('operation', 'LEAD_DISQUALIFY')->count());

        $this->expectException(AuthorizationException::class);
        app(DecideLead::class)->disqualify(Lead::query()->findOrFail($id), $viewer, 'x', 'k', null);
    }

    public function test_another_companys_enquiry_is_unreachable(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $company = DB::table('companies')->insertGetId(['name' => 'Other Company', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $this->postJson("/api/v1/leads/{$id}/disqualify", ['reason' => 'x', 'request_key' => 'k', 'expected_decision_event_id' => null])->assertNotFound();
    }

    public function test_the_database_keeps_receipts_append_only_and_single_subject(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $this->disqualify($id)->assertOk();

        foreach ([fn () => DB::table('sales_crm_operation_receipts')->update(['operation' => 'X']), fn () => DB::table('sales_crm_operation_receipts')->delete()] as $attack) {
            DB::beginTransaction();
            try {
                $attack();
                $this->fail('Receipts must be append-only.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            } finally {
                DB::rollBack();
            }
        }
        $receipt = (array) DB::table('sales_crm_operation_receipts')->first();
        unset($receipt['id']);
        DB::beginTransaction();
        try {
            DB::table('sales_crm_operation_receipts')->insert(array_merge($receipt, ['request_key' => 'two-subjects', 'opportunity_id' => 1, 'result' => '{}']));
            $this->fail('A receipt has exactly one subject.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }
}
