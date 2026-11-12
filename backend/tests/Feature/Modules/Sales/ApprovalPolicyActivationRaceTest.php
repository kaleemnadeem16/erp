<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Modules\Approvals\Actions\ActivateApprovalPolicy;
use App\Modules\Approvals\Actions\DecideApproval;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\RequestQuotationApproval;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovals;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalSubject;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsDiscountAuthority;
use Tests\Feature\Security\Concerns\ApprovalPolicyActivationRace;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\TestCase;

/**
 * ADR 0164: activating a replacement approval-routing policy (a governed change, fenced by the statement barrier on approval_policies) racing a Sales approval
 * decision and a Sales quotation issue on separate real PostgreSQL sessions, in both orders. Sales routing uses strict policy selection, so a request routed under
 * version 1 is judged against whichever version is live under the lock: activation first refuses the decision/issue whole; the action first commits against version 1
 * and activation then waits its turn and commits afterwards. Never a deadlock, never a mixed state.
 */
class ApprovalPolicyActivationRaceTest extends TestCase
{
    use ApprovalPolicyActivationRace;
    use BuildsDiscountAuthority;
    use DatabaseMigrations;
    use TwoSessionRace;

    private const ACCESS_FENCE = 'select company_id from company_access_generations order by company_id for update';

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            DB::setDefaultConnection(config('database.default'));
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    /** @return array{revision: \App\Modules\Sales\Models\QuotationRevision, request: \App\Modules\Approvals\Models\ApprovalRequest, v1: int, v2: int} */
    private function stage(bool $approved): array
    {
        $this->setUpDiscountAuthority();
        Permission::findOrCreate(Duty::AUTHORITY_EVIDENCE_VIEW, 'web');
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated([Duty::AUTHORITY_EVIDENCE_VIEW]));
        $this->checker->givePermissionTo([Duty::AUTHORITY_EVIDENCE_VIEW, Duty::QUOTATION_VIEW]);
        $revision = $this->discount($this->authorityDraft(), '8');
        $this->authorityAssignment($this->authorityPolicy(), 3, $this->checker, ['purpose' => 'APPROVE']);
        $this->authorityMode('ENFORCE');
        ApprovalSubjectSetting::query()->create(['subject_type' => QuotationApprovalSubject::CODE, 'mode' => ApprovalMode::Required]);
        $v1 = $this->routing(1, ApprovalPolicyStatus::Active);
        $v2 = $this->routing(2, ApprovalPolicyStatus::Draft);
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $request = app(QuotationApprovals::class)->latest($revision->id);
        $this->assertSame($v1->id, $request->policy_id);
        if ($approved) {
            app(DecideApproval::class)($request, $this->checker, ApprovalDecisionKind::Approved);
        }

        return ['revision' => $revision, 'request' => $request->refresh(), 'v1' => $v1->id, 'v2' => $v2->id];
    }

    private function routing(int $version, ApprovalPolicyStatus $status): ApprovalPolicy
    {
        $policy = ApprovalPolicy::query()->create(['subject_type' => QuotationApprovalSubject::CODE, 'code' => 'race-review', 'version_no' => $version, 'name' => 'Independent review v'.$version, 'priority' => 0,
            'status' => $status, 'effective_from' => $status === ApprovalPolicyStatus::Active ? now()->toDateString() : null, 'reason' => 'Test routing', 'changed_by' => $this->maker->id]);
        $policy->steps()->create(['stage' => 1, 'name' => 'Review', 'required_approvals' => 1]);

        return $policy;
    }

    private function decisionAction(array $f): \Closure
    {
        return function () use ($f): void {
            $this->shortChildLockTimeout();
            app(DecideApproval::class)($f['request'], $this->checker, ApprovalDecisionKind::Approved);
        };
    }

    private function issueAction(array $f): \Closure
    {
        return function () use ($f): void {
            $this->shortChildLockTimeout();
            app(IssueQuotation::class)($this->checker, $f['revision']->id, $f['revision']->input_generation, 'race-issue-key');
        };
    }

    private function pdo(): \PDO
    {
        $pdo = $this->raceSession();
        $pdo->exec("SET lock_timeout = '5s'");

        return $pdo;
    }

    private function assertV2LiveAndV1Superseded(array $f): void
    {
        $this->assertSame(['active' => [$f['v2']], 'superseded' => [$f['v1']]], $this->policyStates($this->pdo(), QuotationApprovalSubject::CODE, 'race-review'));
    }

    public function test_a_sales_decision_after_activation_is_refused_whole_against_the_new_version(): void
    {
        $f = $this->stage(false);
        $outcome = $this->raceAction($this->pdo(), self::ACCESS_FENCE, $this->decisionAction($f), fn (\PDO $holder) => $this->activatePolicy($holder, $f['v1'], $f['v2']));

        $this->assertFalse($outcome['success'], json_encode($outcome));
        $this->assertSame(\App\Exceptions\DomainInvariantException::class, $outcome['class'], json_encode($outcome));
        $this->assertStringContainsString('not eligible to decide', (string) $outcome['message']);
        $this->assertNoDeadlockOrTimeout($outcome);
        $this->assertSame(0, $f['request']->decisions()->count());
        $this->assertSame('PENDING', $f['request']->refresh()->status->value);
        $this->assertSame($f['v1'], $f['request']->policy_id);
        $this->assertV2LiveAndV1Superseded($f);
    }

    public function test_a_sales_decision_that_got_there_first_commits_against_version_one_and_activation_waits_then_commits(): void
    {
        $f = $this->stage(false);
        $outcome = $this->raceAction($this->pdo(), self::ACCESS_FENCE, function () use ($f): void {
            $this->activationMustWaitForActionOnceRequestLocked($f['v1'], $f['v2']);
            ($this->decisionAction($f))();
        }, fn () => null);

        $this->assertTrue($outcome['success'], json_encode($outcome));
        $this->assertSame(1, $f['request']->decisions()->count());
        $this->assertSame('APPROVED', $f['request']->refresh()->status->value);
        $this->assertSame($f['v1'], $f['request']->policy_id);
        $this->assertSame(['active' => [$f['v1']], 'superseded' => []], $this->policyStates($this->pdo(), QuotationApprovalSubject::CODE, 'race-review'), 'activation has not slipped in');

        app(ActivateApprovalPolicy::class)(ApprovalPolicy::query()->findOrFail($f['v2']), $this->maker->givePermissionTo($this->grantManage()));
        $this->assertV2LiveAndV1Superseded($f);
        $this->assertSame(1, $f['request']->decisions()->count(), 'the committed decision is unchanged by the later activation');
        $this->assertFalse(DB::transaction(fn () => app(QuotationApprovals::class)->isCurrent($f['revision']->refresh(), $f['request'])), 'once v2 is live the v1 approval no longer clears issue');
    }

    public function test_a_sales_issue_after_activation_is_refused_whole_and_issues_nothing(): void
    {
        $f = $this->stage(true);
        $outcome = $this->raceAction($this->pdo(), self::ACCESS_FENCE, $this->issueAction($f), fn (\PDO $holder) => $this->activatePolicy($holder, $f['v1'], $f['v2']));

        $this->assertFalse($outcome['success'], json_encode($outcome));
        $this->assertStringContainsString('APPROVAL_REQUIRED', (string) $outcome['message']);
        $this->assertNoDeadlockOrTimeout(['message' => preg_replace('/APPROVAL_REQUIRED/', '', (string) $outcome['message'])] + $outcome);
        $this->assertSame(0, DB::table('quotation_issues')->where('revision_id', $f['revision']->id)->count());
        $this->assertSame(0, DB::table('numbering_allocations')->where('doc_type', 'QUOTATION')->count());
        $this->assertSame('DRAFT', DB::table('quotation_revisions')->where('id', $f['revision']->id)->value('state'));
        $this->assertV2LiveAndV1Superseded($f);
    }

    public function test_a_sales_issue_that_got_there_first_commits_on_version_one_and_activation_waits_then_commits(): void
    {
        $f = $this->stage(true);
        $outcome = $this->raceAction($this->pdo(), self::ACCESS_FENCE, function () use ($f): void {
            $this->activationMustWaitForActionOnceRequestLocked($f['v1'], $f['v2']);
            ($this->issueAction($f))();
        }, fn () => null);

        $this->assertTrue($outcome['success'], json_encode($outcome));
        $issue = DB::table('quotation_issues')->where('revision_id', $f['revision']->id)->first();
        $this->assertNotNull($issue);
        $this->assertSame($f['request']->id, (int) $issue->approval_request_id, 'issued on the approval routed under version 1');
        $this->assertSame(['active' => [$f['v1']], 'superseded' => []], $this->policyStates($this->pdo(), QuotationApprovalSubject::CODE, 'race-review'), 'activation has not slipped in');

        app(ActivateApprovalPolicy::class)(ApprovalPolicy::query()->findOrFail($f['v2']), $this->maker->givePermissionTo($this->grantManage()));
        $this->assertV2LiveAndV1Superseded($f);
        $this->assertSame('ISSUED', DB::table('quotation_revisions')->where('id', $f['revision']->id)->value('state'), 'the committed issue stands');
        $this->assertSame(1, DB::table('quotation_issues')->where('revision_id', $f['revision']->id)->count());
    }

    private function grantManage(): string
    {
        Permission::findOrCreate('approvals.policy.manage', 'web');

        return 'approvals.policy.manage';
    }
}
