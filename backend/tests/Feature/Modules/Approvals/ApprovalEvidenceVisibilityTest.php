<?php

namespace Tests\Feature\Modules\Approvals;

use App\Models\User;
use App\Modules\Approvals\Actions\SubmitForApproval;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Estimating\Support\MaterialVarianceApprovalSubject;
use App\Modules\Estimating\Support\StockMixApprovalSubject;
use App\Modules\Purchasing\Support\PurchaseOrderApprovalSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeApprovalSubject;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/**
 * Approval authority is not a licence to read commercial or cost data (docs/architecture/authorization.md): the routed amount and
 * its currency evidence follow the document's own field policy, with a need-to-know exception for whoever is asked to decide or has
 * decided that request. An auditor sees the decisions without the money. Checked on the response *bodies*, nested evidence included.
 */
class ApprovalEvidenceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private int $requestId;

    private User $submitter;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['test.document.approve', 'test.document.cost.view', 'approvals.policy.manage', 'approvals.history.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $subject = new FakeApprovalSubject;
        $subject->documents[1] = ['amount' => '80000.0000', 'warehouse' => 'main'];
        app(ApprovalSubjectRegistry::class)->register($subject);
        ApprovalSubjectSetting::query()->create(['subject_type' => 'TEST_DOCUMENT', 'mode' => ApprovalMode::Policy]);
        $admin = $this->user('approvals.policy.manage');
        $policy = $this->actingAs($admin)->postJson('/api/v1/approval-policies', [
            'subject_type' => 'TEST_DOCUMENT', 'code' => 'all', 'name' => 'All', 'priority' => 10, 'reason' => 'Policy',
            'conditions' => [], 'steps' => [['name' => 'Manager', 'required_approvals' => 1]],
        ])->json('data.id');
        $this->postJson("/api/v1/approval-policies/{$policy}/activate")->assertOk();
        $this->submitter = $this->user();
        $this->requestId = app(SubmitForApproval::class)('TEST_DOCUMENT', 1, $this->submitter)->id;
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /** The whole response body, so a figure hidden from one field but leaked in another still fails. */
    private function assertNoMoney(string $body): void
    {
        $this->assertStringNotContainsString('80000', $body);
        $this->assertStringNotContainsString('fx_rate', $body);
        $this->assertStringNotContainsString('original_amount', $body);
    }

    public function test_an_auditor_sees_the_decisions_but_not_the_money(): void
    {
        $auditor = $this->user('approvals.history.view');

        $list = $this->actingAs($auditor)->getJson('/api/v1/approval-requests')->assertOk();
        $list->assertJsonPath('data.0.evidence_visible', false)->assertJsonPath('data.0.amount_base', null)->assertJsonPath('data.0.evidence', []);
        $this->assertNoMoney($list->getContent());

        $show = $this->getJson("/api/v1/approval-requests/{$this->requestId}")->assertOk()->assertJsonPath('data.evidence_visible', false);
        $this->assertNoMoney($show->getContent());
        $show->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.steps.0.name', 'Manager');
    }

    public function test_the_submitter_without_cost_visibility_does_not_get_the_money_back(): void
    {
        $show = $this->actingAs($this->submitter)->getJson("/api/v1/approval-requests/{$this->requestId}")->assertOk();
        $show->assertJsonPath('data.evidence_visible', false);
        $this->assertNoMoney($show->getContent());
    }

    public function test_whoever_may_see_the_documents_figures_sees_them_on_the_approval(): void
    {
        $viewer = $this->user('approvals.history.view', 'test.document.cost.view');

        $this->actingAs($viewer)->getJson("/api/v1/approval-requests/{$this->requestId}")->assertOk()
            ->assertJsonPath('data.evidence_visible', true)->assertJsonPath('data.amount_base', '80000.0000')
            ->assertJsonPath('data.evidence.fx_rate_source', 'manual');
    }

    public function test_the_person_asked_to_decide_sees_the_money_for_that_request_and_keeps_sight_of_their_decision(): void
    {
        $approver = $this->user('test.document.approve');

        $this->actingAs($approver)->getJson('/api/v1/approval-requests/inbox')->assertOk()
            ->assertJsonPath('data.0.evidence_visible', true)->assertJsonPath('data.0.amount_base', '80000.0000');

        $this->postJson("/api/v1/approval-requests/{$this->requestId}/decide", ['decision' => 'APPROVED', 'reason' => 'ok'])->assertOk()
            ->assertJsonPath('data.evidence_visible', true);
        // Their authority is gone once the request closes, but the decision they made still carries its basis.
        $this->getJson("/api/v1/approval-requests/{$this->requestId}")->assertOk()->assertJsonPath('data.amount_base', '80000.0000');
        // Someone who merely holds the approve permission, not asked to decide, gets nothing: not involved, and no cost visibility.
        $bystander = $this->user('test.document.approve');
        $this->actingAs($bystander)->getJson("/api/v1/approval-requests/{$this->requestId}")->assertForbidden();
    }

    public function test_every_registered_subject_declares_who_may_see_its_figures(): void
    {
        $buyer = PurchasingActor::make(['purchasing.purchase-order.view']);
        $costViewer = PurchasingActor::make(['purchasing.commercial-cost.view']);
        $drafter = PurchasingActor::make(['purchasing.purchase-order.create']);
        $po = new PurchaseOrderApprovalSubject;
        $this->assertFalse($po->canViewEvidence($buyer));
        $this->assertTrue($po->canViewEvidence($costViewer));
        $this->assertTrue($po->canViewEvidence($drafter)); // drafting a priced order already reads its commercial fields

        $estimator = PurchasingActor::make(['estimating.stock_mix.approve', 'estimating.material_variance.approve']);
        $costEstimator = PurchasingActor::make(['estimating.internal_cost.view']);
        foreach ([new StockMixApprovalSubject, new MaterialVarianceApprovalSubject] as $subject) {
            $this->assertFalse($subject->canViewEvidence($estimator), $subject->code());
            $this->assertTrue($subject->canViewEvidence($costEstimator), $subject->code());
        }
    }
}
