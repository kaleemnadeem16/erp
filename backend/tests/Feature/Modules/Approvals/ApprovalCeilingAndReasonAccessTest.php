<?php

namespace Tests\Feature\Modules\Approvals;

use App\Models\User;
use App\Modules\Approvals\Actions\SubmitForApproval;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeApprovalSubject;
use Tests\TestCase;

/**
 * ADR 0087 (Q35): a person's spending ceiling and the free text written on an approval are not part of "may read the approval history".
 * Ceilings need `approvals.authority.manage` or `approvals.authority.view`; a person-written reason follows the evidence, plus
 * `approvals.reason.view` for an auditor and the author's own words. Everything is checked on the response bodies, because a reason can
 * repeat the figure a structured field withholds.
 */
class ApprovalCeilingAndReasonAccessTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'Unit price 80000 is far too high';

    private FakeApprovalSubject $subject;

    private User $submitter;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['test.document.approve', 'test.document.cost.view', 'approvals.policy.manage', 'approvals.history.view', 'approvals.authority.manage', 'approvals.authority.view', 'approvals.reason.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->subject = new FakeApprovalSubject;
        foreach ([1, 2, 3, 4] as $id) {
            $this->subject->documents[$id] = ['amount' => '80000.0000', 'warehouse' => 'main'];
        }
        app(ApprovalSubjectRegistry::class)->register($this->subject);
        ApprovalSubjectSetting::query()->create(['subject_type' => 'TEST_DOCUMENT', 'mode' => ApprovalMode::Policy]);
        $policy = $this->actingAs($this->user('approvals.policy.manage'))->postJson('/api/v1/approval-policies', [
            'subject_type' => 'TEST_DOCUMENT', 'code' => 'all', 'name' => 'All', 'priority' => 10, 'reason' => 'Policy',
            'conditions' => [], 'steps' => [['name' => 'Manager', 'required_approvals' => 1]],
        ])->json('data.id');
        $this->postJson("/api/v1/approval-policies/{$policy}/activate")->assertOk();
        $this->submitter = $this->user();
        $this->approver = $this->user('test.document.approve');
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function submit(int $document): int
    {
        return app(SubmitForApproval::class)('TEST_DOCUMENT', $document, $this->submitter)->id;
    }

    private function rejected(int $document = 1): int
    {
        $id = $this->submit($document);
        $this->actingAs($this->approver)->postJson("/api/v1/approval-requests/{$id}/decide", ['decision' => 'REJECTED', 'reason' => self::SECRET])->assertOk();

        return $id;
    }

    /** @return array<string, mixed> */
    private function show(User $user, int $id): array
    {
        return $this->actingAs($user)->getJson("/api/v1/approval-requests/{$id}")->assertOk()->json('data');
    }

    public function test_ceilings_need_the_manage_or_view_capability_and_history_alone_no_longer_reveals_them(): void
    {
        $manager = $this->user('approvals.authority.manage');
        $this->actingAs($manager)->postJson('/api/v1/approval-authorities', [
            'user_id' => $this->approver->id, 'subject_type' => 'TEST_DOCUMENT', 'ceiling_base_amount' => '50000.0000', 'reason' => 'Manager limit',
        ])->assertCreated();

        $historyOnly = $this->actingAs($this->user('approvals.history.view'))->getJson('/api/v1/approval-authorities');
        $historyOnly->assertForbidden();
        $this->assertStringNotContainsString('50000', $historyOnly->getContent());
        $this->actingAs($this->user('approvals.history.view', 'approvals.reason.view'))->getJson('/api/v1/approval-authorities')->assertForbidden();

        foreach (['approvals.authority.manage', 'approvals.authority.view'] as $capability) {
            $this->actingAs($this->user($capability))->getJson('/api/v1/approval-authorities')->assertOk()
                ->assertJsonPath('data.0.user_id', $this->approver->id)->assertJsonPath('data.0.ceiling_base_amount', '50000.0000');
        }

        // The auditor capability reads limits; it does not change them.
        $this->actingAs($this->user('approvals.authority.view'))->postJson('/api/v1/approval-authorities', [
            'user_id' => $this->approver->id, 'subject_type' => 'TEST_DOCUMENT', 'ceiling_base_amount' => '999999.0000', 'reason' => 'Self-service',
        ])->assertForbidden();

        // Everyone can still see their own limit, and nobody else's.
        $this->actingAs($this->approver)->getJson('/api/v1/approval-authorities/mine')->assertOk()->assertJsonPath('data.0.ceiling_base_amount', '50000.0000');
        $this->actingAs($this->user())->getJson('/api/v1/approval-authorities/mine')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_a_rejection_reason_is_withheld_from_everyone_the_evidence_is_withheld_from(): void
    {
        $id = $this->rejected();

        foreach ([
            'a history reader' => $this->user('approvals.history.view'),
            'an authority auditor' => $this->user('approvals.history.view', 'approvals.authority.view'),
            'the submitter, who did not write it' => $this->submitter,
        ] as $who => $reader) {
            $body = $this->actingAs($reader)->getJson("/api/v1/approval-requests/{$id}");
            $body->assertOk()->assertJsonPath('data.status', 'REJECTED')
                ->assertJsonPath('data.closing_reason', null)->assertJsonPath('data.closing_reason_visible', false)
                ->assertJsonPath('data.steps.0.decisions.0.decision', 'REJECTED')->assertJsonPath('data.steps.0.decisions.0.reason', null)
                ->assertJsonPath('data.steps.0.decisions.0.reason_visible', false);
            $this->assertStringNotContainsString('far too high', $body->getContent(), "{$who} must not read the reason");
            $this->assertStringNotContainsString('80000', $body->getContent(), "{$who} must not read a figure through the reason");
            // The outcome, who decided and when are still there.
            $this->assertSame($this->approver->id, $body->json('data.steps.0.decisions.0.decided_by.id'));
        }
        // The list a history reader browses withholds it the same way.
        $list = $this->actingAs($this->user('approvals.history.view'))->getJson('/api/v1/approval-requests')->assertOk();
        $this->assertStringNotContainsString('far too high', $list->getContent());
    }

    public function test_a_rejection_reason_is_read_by_the_evidence_holder_the_auditor_and_its_author(): void
    {
        $id = $this->rejected();

        foreach ([
            'a reader of the document figures' => $this->user('approvals.history.view', 'test.document.cost.view'),
            'a reason auditor' => $this->user('approvals.history.view', 'approvals.reason.view'),
            'its author' => $this->approver,
        ] as $who => $reader) {
            $data = $this->show($reader, $id);
            $this->assertSame(self::SECRET, $data['closing_reason'], $who);
            $this->assertTrue($data['closing_reason_visible'], $who);
            $this->assertSame(self::SECRET, $data['steps'][0]['decisions'][0]['reason'], $who);
            $this->assertTrue($data['steps'][0]['decisions'][0]['reason_visible'], $who);
        }
    }

    public function test_a_delegator_reads_the_reason_their_delegate_wrote_in_their_name(): void
    {
        $delegator = $this->user('test.document.approve');
        $delegate = User::factory()->create();
        ApprovalDelegation::create(['delegator_id' => $delegator->id, 'delegate_id' => $delegate->id, 'valid_from' => today(), 'valid_to' => today()->addDay(), 'reason' => 'Cover', 'created_by' => $delegator->id]);
        $id = $this->submit(2);
        $this->actingAs($delegate)->postJson("/api/v1/approval-requests/{$id}/decide", ['decision' => 'REJECTED', 'reason' => self::SECRET])->assertOk();

        $this->assertSame(self::SECRET, $this->show($delegator, $id)['steps'][0]['decisions'][0]['reason']);
        $this->assertSame(self::SECRET, $this->show($delegate, $id)['steps'][0]['decisions'][0]['reason']);
        $this->assertNull($this->show($this->user('approvals.history.view'), $id)['steps'][0]['decisions'][0]['reason']);
    }

    public function test_a_withdrawal_reason_is_the_submitters_and_is_withheld_from_other_history_readers(): void
    {
        $id = $this->submit(3);
        $this->actingAs($this->submitter)->postJson("/api/v1/approval-requests/{$id}/withdraw", ['reason' => 'Supplier now asks 90000'])->assertOk();

        $this->assertSame('Supplier now asks 90000', $this->show($this->submitter, $id)['closing_reason']);
        $other = $this->actingAs($this->user('approvals.history.view'))->getJson("/api/v1/approval-requests/{$id}");
        $other->assertJsonPath('data.status', 'WITHDRAWN')->assertJsonPath('data.closing_reason', null)->assertJsonPath('data.closing_reason_visible', false);
        $this->assertStringNotContainsString('90000', $other->getContent());
        $this->assertSame('Supplier now asks 90000', $this->show($this->user('approvals.history.view', 'approvals.reason.view'), $id)['closing_reason']);
    }

    public function test_reasons_the_system_writes_stay_visible_because_they_quote_no_figures(): void
    {
        $id = $this->submit(4);
        $this->subject->documents[4]['amount'] = '95000.0000';
        $this->actingAs($this->approver)->postJson("/api/v1/approval-requests/{$id}/decide", ['decision' => 'APPROVED'])->assertUnprocessable();

        $data = $this->show($this->user('approvals.history.view'), $id);
        $this->assertSame('INVALIDATED', $data['status']);
        $this->assertSame('The document changed after it was submitted for approval; it must be submitted again.', $data['closing_reason']);
        $this->assertTrue($data['closing_reason_visible']);
        $this->assertStringNotContainsString('95000', json_encode($data));
    }
}
