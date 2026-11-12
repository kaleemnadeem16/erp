<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Approvals\Actions\DecideApproval;
use App\Modules\Approvals\Actions\SubmitForApproval;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\Feature\Security\Concerns\ApprovalPolicyActivationRace;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\TestCase;

/**
 * ADR 0164: activating a replacement Purchase Order approval policy (statement barrier on approval_policies) racing a PO approval decision on real separate sessions,
 * in both orders. PO decisions take the shared company access fence, then the PO header, then the request (no authority head). A request keeps the policy version it was
 * routed under (ADR 0070 section 3): version 2 here demands a permission the approver does not hold, so a decision judged against version 2 would be refused; judged
 * against version 1 (the routed version) it is recorded - in either order, with no deadlock and no mixed state.
 */
class ApprovalPolicyActivationPurchaseOrderRaceTest extends TestCase
{
    use ApprovalPolicyActivationRace;
    use RefreshDatabase;
    use TwoSessionRace;

    private const APPROVE = 'purchasing.purchase-order.approve';

    private const V2_ONLY = 'purchasing.purchase-order.approve-v2-only';

    /** @return array{pdo: PDO, company: int, request: int, v1: int, v2: int} */
    private function fixture(): array
    {
        $pdo = $this->raceSession();
        $pdo->exec("SET lock_timeout = '5s'");
        $prefix = 'pa'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $submitter = $this->raceUser($pdo, $r['company'], $prefix, 'submitter');
        $approver = $this->raceUser($pdo, $r['company'], $prefix, 'approver');
        $this->raceGrant($pdo, $r['company'], $approver, self::APPROVE);
        $this->raceBindCompany($r['company']);
        $po = (int) $pdo->query("insert into purchase_orders (company_id, supplier_account_id, currency_id, fx_rate, fx_rate_date, fx_rate_type, fx_rate_source, order_date, created_at, updated_at) values ({$r['company']}, {$r['supplier']}, {$r['currency']}, 1, current_date, 'SPOT', 'manual', current_date, now(), now()) returning id")->fetchColumn();
        $pdo->exec("insert into purchase_order_lines (company_id, purchase_order_id, line_number, item_id, description, ordered_qty, purchase_uom_id, ordered_stock_qty, stock_uom_id, uom_factor, unit_price, line_amount, base_unit_price, base_line_amount, qa_required, over_receipt_mode, created_at, updated_at) values ({$r['company']}, {$po}, 1, {$r['item']}, 'l', 10, {$r['uom']}, 10, {$r['uom']}, 1, 10, 100, 10, 100, false, 'BLOCK', now(), now())");
        $ids = $this->raceCommitted(function () use ($submitter, $po): array {
            ApprovalSubjectSetting::query()->updateOrCreate(['subject_type' => 'PURCHASE_ORDER'], ['mode' => ApprovalMode::Required]);
            $v1 = ApprovalPolicy::query()->create(['subject_type' => 'PURCHASE_ORDER', 'code' => 'race-po', 'version_no' => 1, 'name' => 'v1', 'priority' => 0, 'status' => ApprovalPolicyStatus::Active, 'reason' => 't', 'changed_by' => $submitter]);
            $v1->steps()->create(['stage' => 1, 'name' => 'S1', 'required_approvals' => 1, 'permission' => self::APPROVE]);
            $v2 = ApprovalPolicy::query()->create(['subject_type' => 'PURCHASE_ORDER', 'code' => 'race-po', 'version_no' => 2, 'name' => 'v2', 'priority' => 0, 'status' => ApprovalPolicyStatus::Draft, 'reason' => 't', 'changed_by' => $submitter]);
            $v2->steps()->create(['stage' => 1, 'name' => 'S1', 'required_approvals' => 1, 'permission' => self::V2_ONLY]);
            $request = app(SubmitForApproval::class)('PURCHASE_ORDER', $po, User::query()->findOrFail($submitter));

            return [$request->id, $v1->id, $v2->id, $request->policy_id];
        });
        $this->assertSame($ids[1], $ids[3], 'the request is routed under version 1');

        return ['pdo' => $pdo, 'company' => $r['company'], 'request' => $ids[0], 'v1' => $ids[1], 'v2' => $ids[2], 'approver' => $approver];
    }

    private function decide(array $f): \Closure
    {
        return function () use ($f): void {
            $this->shortChildLockTimeout();
            app(DecideApproval::class)(ApprovalRequest::query()->findOrFail($f['request']), User::query()->findOrFail($f['approver']), ApprovalDecisionKind::Approved);
        };
    }

    private function fence(array $f): string
    {
        return "select company_id from company_access_generations where company_id = {$f['company']} for update";
    }

    private function assertDecidedOnVersionOne(array $f): void
    {
        $this->assertSame('1', (string) $f['pdo']->query("select count(*) from approval_decisions where request_id = {$f['request']}")->fetchColumn());
        $row = $f['pdo']->query("select status, policy_id from approval_requests where id = {$f['request']}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('APPROVED', $row['status']);
        $this->assertSame($f['v1'], (int) $row['policy_id']);
    }

    public function test_a_po_decision_after_activation_is_judged_against_the_version_it_was_routed_under(): void
    {
        $f = $this->fixture();
        try {
            $outcome = $this->raceAction($f['pdo'], $this->fence($f), $this->decide($f), fn (PDO $holder) => $this->activatePolicy($holder, $f['v1'], $f['v2']));

            $this->assertTrue($outcome['success'], json_encode($outcome));
            $this->assertDecidedOnVersionOne($f);
            $this->assertSame(['active' => [$f['v2']], 'superseded' => [$f['v1']]], $this->policyStates($f['pdo'], 'PURCHASE_ORDER', 'race-po'));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['company']);
        }
    }

    public function test_a_po_decision_that_got_there_first_commits_and_activation_waits_then_commits(): void
    {
        $f = $this->fixture();
        try {
            $outcome = $this->raceAction($f['pdo'], $this->fence($f), function () use ($f): void {
                $this->activationMustWaitForActionOnceRequestLocked($f['v1'], $f['v2']);
                ($this->decide($f))();
            }, fn () => null);

            $this->assertTrue($outcome['success'], json_encode($outcome));
            $this->assertDecidedOnVersionOne($f);
            $this->assertSame(['active' => [$f['v1']], 'superseded' => []], $this->policyStates($f['pdo'], 'PURCHASE_ORDER', 'race-po'), 'activation has not slipped in');

            $f['pdo']->exec("SET lock_timeout = '5s'");
            $this->activatePolicy($f['pdo'], $f['v1'], $f['v2']);
            $this->assertSame(['active' => [$f['v2']], 'superseded' => [$f['v1']]], $this->policyStates($f['pdo'], 'PURCHASE_ORDER', 'race-po'));
            $this->assertDecidedOnVersionOne($f);
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['company']);
        }
    }
}
