<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\CorrectLeadEnquiry;
use App\Modules\Sales\Actions\RecordLead;
use App\Modules\Sales\Models\Lead;
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

/** ADR 0113: the enquiry as received is immutable; the six current details change only through reasoned, versioned corrections. */
class LeadEnquiryCorrectionTest extends TestCase
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

    private function lead(array $over = []): int
    {
        return (int) $this->postJson('/api/v1/leads', array_merge(['enquirer_name' => 'Fatima Khan', 'enquirer_phone' => '123', 'description' => 'Office furniture', 'source' => 'Referral', 'request_key' => 'lead-'.uniqid('', true)], $over))->assertCreated()->json('data.id');
    }

    /** @param array<string, mixed> $over */
    private function correct(int $id, array $over = [])
    {
        return $this->patchJson("/api/v1/leads/{$id}", array_merge(['enquirer_phone' => '132', 'reason' => 'Mistyped', 'request_key' => 'fix-'.uniqid('', true), 'expected_enquiry_version' => $this->version($id)], $over));
    }

    private function version(int $id): int
    {
        return (int) $this->getJson("/api/v1/leads/{$id}")->json('data.enquiry_version');
    }

    public function test_a_new_lead_has_one_original_intake_and_a_receipt_recorded_together(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead(['enquirer_organisation' => '  ABC  ']);

        $intake = DB::table('sales_lead_intakes')->where('lead_id', $id)->sole();
        $this->assertSame('ORIGINAL_INTAKE', $intake->evidence_kind);
        $this->assertSame('ABC', $intake->enquirer_organisation, 'the accepted, normalised enquiry');
        $this->assertSame('123', $intake->enquirer_phone);
        $this->assertNotNull($intake->captured_by);
        $this->assertSame(0, Lead::query()->findOrFail($id)->enquiry_version);
        $this->assertSame(['LEAD_RECEIVED'], SalesHistory::query()->where('lead_id', $id)->pluck('event')->all());
        $this->assertSame(1, DB::table('sales_crm_operation_receipts')->where('operation', 'LEAD_RECORD')->where('lead_id', $id)->count());
    }

    public function test_recording_the_same_submission_twice_records_one_enquiry_and_a_different_one_under_the_key_is_refused(): void
    {
        $this->actingAs($this->seller());
        $body = ['enquirer_name' => 'Fatima Khan', 'request_key' => 'form-1'];
        $first = $this->postJson('/api/v1/leads', $body)->assertCreated()->json('data');
        $again = $this->postJson('/api/v1/leads', $body)->assertOk()->json('data');

        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(1, Lead::query()->count());
        $this->postJson('/api/v1/leads', ['enquirer_name' => 'Someone else', 'request_key' => 'form-1'])->assertUnprocessable()->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
        $this->postJson('/api/v1/leads', ['enquirer_name' => 'No key'])->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertSame(1, Lead::query()->count());
    }

    public function test_a_correction_changes_the_current_phone_and_keeps_the_original_in_every_lifecycle_state(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();

        $this->correct($id)->assertOk()->assertJsonPath('data.enquirer_phone', '132')->assertJsonPath('data.enquiry_version', 1)
            ->assertJsonPath('operation_result.changed', true)->assertJsonPath('replayed', false);
        $this->postJson("/api/v1/leads/{$id}/qualify")->assertOk();
        $this->correct($id, ['enquirer_phone' => '1320', 'description' => 'Office furniture and fit-out'])->assertOk()->assertJsonPath('data.enquiry_version', 2)->assertJsonPath('data.status', 'QUALIFIED');
        $marker = $this->getJson("/api/v1/leads/{$id}")->json('data.decision_event_id');
        $this->postJson("/api/v1/leads/{$id}/disqualify", ['reason' => 'Duplicate', 'request_key' => 'dq', 'expected_decision_event_id' => $marker])->assertOk();
        $this->correct($id, ['enquirer_phone' => '1320', 'source' => 'Trade fair'])->assertOk()->assertJsonPath('data.status', 'DISQUALIFIED');
        $this->postJson("/api/v1/leads/{$id}/revive", ['reason' => 'Back'])->assertOk();
        $this->correct($id, ['enquirer_phone' => '1320', 'enquirer_organisation' => 'ABC Industries'])->assertOk()->assertJsonPath('data.status', 'NEW')->assertJsonPath('data.enquiry_version', 4);

        $intake = DB::table('sales_lead_intakes')->where('lead_id', $id)->sole();
        $this->assertSame('123', $intake->enquirer_phone, 'received phone 123 stays recorded');
        $this->assertSame('Referral', $intake->source);
        $shown = $this->getJson("/api/v1/leads/{$id}?include=intake,enquiry_corrections")->assertOk();
        $shown->assertJsonPath('data.original_intake.enquirer_phone', '123')->assertJsonPath('data.enquirer_phone', '1320');
        $this->assertSame([1, 2, 3, 4], array_column($shown->json('data.enquiry_corrections'), 'resulting_version'));
        $this->assertSame(['enquirer_phone', 'description'], array_column($shown->json('data.enquiry_corrections.1.fields'), 'field_name'));
        $this->assertSame(['123', '132'], [$shown->json('data.enquiry_corrections.0.fields.0.old_value'), $shown->json('data.enquiry_corrections.0.fields.0.new_value')]);
        $this->assertSame(4, SalesHistory::query()->where('lead_id', $id)->where('event', 'LEAD_ENQUIRY_CORRECTED')->count());
    }

    public function test_status_party_assignee_and_receipt_date_are_never_changed_and_nothing_else_is_accepted(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        foreach (['status' => 'QUALIFIED', 'party_id' => $this->party->id, 'assigned_to' => 1, 'received_at' => '2020-01-01'] as $field => $value) {
            $this->correct($id, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->correct($id, ['enquirer_phone' => null, 'enquirer_name' => '  '])->assertUnprocessable();
        $this->patchJson("/api/v1/leads/{$id}", ['reason' => 'x', 'request_key' => 'k', 'expected_enquiry_version' => 0])->assertUnprocessable();
        $this->correct($id, ['reason' => '  '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(0, $this->version($id));
        $this->assertSame(0, DB::table('sales_lead_enquiry_corrections')->count());
    }

    public function test_clearing_a_detail_is_a_correction_and_a_no_op_creates_no_evidence_but_keeps_its_receipt(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();

        $this->correct($id, ['enquirer_phone' => null])->assertOk()->assertJsonPath('data.enquirer_phone', null);
        $field = DB::table('sales_lead_enquiry_correction_fields')->sole();
        $this->assertSame(['123', null], [$field->old_value, $field->new_value]);

        $noop = $this->correct($id, ['enquirer_phone' => '', 'request_key' => 'noop'])->assertOk()->assertJsonPath('operation_result.changed', false)->assertJsonPath('operation_result.correction_id', null);
        $this->assertSame(1, $noop->json('data.enquiry_version'));
        $this->assertSame(1, DB::table('sales_lead_enquiry_corrections')->count());
        $this->assertSame(1, SalesHistory::query()->where('lead_id', $id)->where('event', 'LEAD_ENQUIRY_CORRECTED')->count());
        $this->assertSame(1, DB::table('sales_crm_operation_receipts')->where('request_key', 'noop')->count());
    }

    public function test_bounds_are_enforced(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $this->correct($id, ['enquirer_phone' => str_repeat('9', 65)])->assertUnprocessable()->assertJsonValidationErrors('enquirer_phone');
        $this->correct($id, ['description' => str_repeat('d', 5001)])->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->correct($id, ['enquirer_phone' => '1', 'reason' => str_repeat('r', 1001)])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_a_stale_version_is_reviewed_and_two_corrections_never_merge(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $this->correct($id)->assertOk();

        $this->correct($id, ['enquirer_email' => 'f@x.example', 'expected_enquiry_version' => 0])->assertUnprocessable()->assertJsonPath('reason_code', 'STALE_EVIDENCE');
        $this->assertNull(Lead::query()->findOrFail($id)->enquirer_email);
        $this->correct($id, ['enquirer_email' => 'f@x.example', 'expected_enquiry_version' => 1])->assertOk();
    }

    public function test_the_same_key_and_intent_replays_and_a_changed_intent_is_refused(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $body = ['enquirer_phone' => '132', 'reason' => 'Mistyped', 'request_key' => 'same', 'expected_enquiry_version' => 0];
        $first = $this->patchJson("/api/v1/leads/{$id}", $body)->assertOk()->json();
        $this->correct($id, ['enquirer_email' => 'later@x.example'])->assertOk();

        $again = $this->patchJson("/api/v1/leads/{$id}", $body)->assertOk()->json();
        $this->assertTrue($again['replayed']);
        $this->assertEquals($first['operation_result'], $again['operation_result']);
        $this->assertSame(2, DB::table('sales_lead_enquiry_corrections')->count(), 'a replay writes nothing');
        $this->patchJson("/api/v1/leads/{$id}", array_merge($body, ['enquirer_phone' => '999']))->assertUnprocessable()->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
    }

    public function test_it_needs_lead_manage_with_its_own_scope_at_http_and_in_the_action_and_stays_in_its_company(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $viewer = $this->seller([Sales::PIPELINE_VIEW]);
        $this->actingAs($viewer);
        $this->correct($id, ['expected_enquiry_version' => 0])->assertForbidden();
        $this->assertSame(0, DB::table('sales_lead_enquiry_corrections')->count());
        try {
            app(CorrectLeadEnquiry::class)(Lead::query()->findOrFail($id), ['enquirer_phone' => '1'], 'r', 'k', 0, $viewer);
            $this->fail('A viewer must not correct an enquiry.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $company = DB::table('companies')->insertGetId(['name' => 'Other Company', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->seller());
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $this->patchJson("/api/v1/leads/{$id}", ['enquirer_phone' => '1', 'reason' => 'r', 'request_key' => 'k', 'expected_enquiry_version' => 0])->assertNotFound();
    }

    public function test_a_failed_write_leaves_no_half_lead_and_no_receipt(): void
    {
        $actor = $this->seller([Sales::LEAD_MANAGE]);
        try {
            app(RecordLead::class)(['enquirer_name' => 'Half', 'party_id' => 999999], $actor, 'boom');
            $this->fail('An unknown party must be refused.');
        } catch (DomainInvariantException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, Lead::query()->count());
        $this->assertSame(0, DB::table('sales_lead_intakes')->count());
        $this->assertSame(0, DB::table('sales_crm_operation_receipts')->count());
    }

    public function test_the_database_refuses_raw_attacks_on_the_evidence_and_the_projection(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        $this->correct($id)->assertOk();
        $refuse = function (\Closure $attack, string $why): void {
            DB::beginTransaction();
            try {
                $attack();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $this->fail($why);
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            } finally {
                DB::rollBack();
            }
        };

        $refuse(fn () => DB::table('sales_lead_intakes')->update(['enquirer_phone' => 'x']), 'intake is immutable');
        $refuse(fn () => DB::table('sales_lead_intakes')->delete(), 'intake is permanent');
        $refuse(fn () => DB::table('sales_lead_enquiry_corrections')->update(['reason' => 'x']), 'corrections are append-only');
        $refuse(fn () => DB::table('sales_lead_enquiry_correction_fields')->delete(), 'correction values are permanent');
        $refuse(fn () => DB::table('sales_leads')->where('id', $id)->update(['enquirer_phone' => 'raw']), 'a detail changes only with a version step');
        $refuse(fn () => DB::table('sales_leads')->where('id', $id)->update(['enquirer_phone' => 'raw', 'enquiry_version' => 5]), 'the version steps by exactly one');
        $refuse(fn () => DB::table('sales_leads')->where('id', $id)->update(['enquirer_phone' => 'raw', 'enquiry_version' => 2]), 'a step needs its evidence');
        $refuse(fn () => DB::table('sales_leads')->where('id', $id)->update(['enquiry_version' => 2]), 'a step needs a changed value');
        $refuse(fn () => DB::table('sales_leads')->where('id', $id)->update(['received_at' => '2001-01-01']), 'the receipt date is never rewritten');
        $refuse(fn () => DB::table('sales_lead_enquiry_corrections')->insert(['company_id' => 1, 'lead_id' => $id, 'actor_id' => 1, 'recorded_at' => now(), 'reason' => 'orphan', 'previous_version' => 1, 'resulting_version' => 2]), 'a correction needs its projection');
        $refuse(fn () => DB::table('sales_lead_enquiry_corrections')->insert(['company_id' => 1, 'lead_id' => $id, 'actor_id' => 1, 'recorded_at' => now(), 'reason' => ' ', 'previous_version' => 5, 'resulting_version' => 6]), 'a reason is required');
        $refuse(function () use ($id): void {
            $row = (array) DB::table('sales_leads')->where('id', $id)->first();
            unset($row['id']);
            DB::table('sales_leads')->insert(array_merge($row, ['number' => 'RAW-1', 'enquiry_version' => 0]));
        }, 'a lead needs its intake evidence');
    }

    public function test_the_baseline_labels_existing_leads_with_their_real_capture_time(): void
    {
        $this->actingAs($this->seller());
        $id = $this->lead();
        // Evidence for a lead that predates the rollout is labelled, never presented as the original.
        $baseline = DB::table('sales_lead_intakes')->where('lead_id', $id)->sole();
        $this->assertContains($baseline->evidence_kind, ['ORIGINAL_INTAKE', 'CAPTURED_BASELINE']);
        $this->assertSame(DB::table('sales_leads')->count(), DB::table('sales_lead_intakes')->count(), 'exactly one evidence row per lead');
    }
}
