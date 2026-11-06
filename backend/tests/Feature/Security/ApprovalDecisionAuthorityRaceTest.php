<?php

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
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\UpdatePurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\TestCase;

/**
 * Issue #91 (audit F4/F5): DecideApproval against real concurrent PostgreSQL sessions. The test holds the approval request's row
 * lock so the Action has already finished its pre-transaction checks and is waiting for its own transaction; a security or
 * document change is then committed and the lock released (see Concerns\TwoSessionRace).
 *
 * These are the guards that HOLD today: authority and delegation are re-read from the database inside the decision transaction,
 * a delegator is re-hydrated there, the pending-approval freeze stops a normal edit, and a stale approval cannot be consumed.
 * The races that do NOT hold (the approver's own permission/suspension, expiry, a document change that bypasses the freeze) are
 * documented in docs/architecture/security-verification-matrix.md and are deliberately not asserted here.
 */
class ApprovalDecisionAuthorityRaceTest extends TestCase
{
    use RefreshDatabase;
    use TwoSessionRace;

    private const APPROVE = 'purchasing.purchase-order.approve';

    /** @return array{pdo: PDO, r: array<string,int>, prefix: string, po: int, submitter: int, approver: int, request: int} */
    private function fixture(bool $limit = false, string $onExpiry = 'FLAG_OVERDUE'): array
    {
        $pdo = $this->raceSession();
        $prefix = 'ar'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $submitter = $this->raceUser($pdo, $r['company'], $prefix, 'submitter');
        $approver = $this->raceUser($pdo, $r['company'], $prefix, 'approver');
        $this->raceGrant($pdo, $r['company'], $approver, self::APPROVE);
        $this->raceBindCompany($r['company']);
        $po = (int) $pdo->query("insert into purchase_orders (company_id, supplier_account_id, currency_id, fx_rate, fx_rate_date, fx_rate_type, fx_rate_source, order_date, created_at, updated_at) values ({$r['company']}, {$r['supplier']}, {$r['currency']}, 1, current_date, 'SPOT', 'manual', current_date, now(), now()) returning id")->fetchColumn();
        $pdo->exec("insert into purchase_order_lines (company_id, purchase_order_id, line_number, item_id, description, ordered_qty, purchase_uom_id, ordered_stock_qty, stock_uom_id, uom_factor, unit_price, line_amount, base_unit_price, base_line_amount, qa_required, over_receipt_mode, created_at, updated_at) values ({$r['company']}, {$po}, 1, {$r['item']}, 'l', 10, {$r['uom']}, 10, {$r['uom']}, 1, 10, 100, 10, 100, false, 'BLOCK', now(), now())");
        $pdo->exec("insert into approval_authorities (company_id, user_id, subject_type, unlimited, reason, changed_by, created_at, updated_at) values ({$r['company']}, {$approver}, 'PURCHASE_ORDER', true, 't', {$submitter}, now(), now())");
        $request = $this->raceCommitted(function () use ($submitter, $po, $limit, $onExpiry): int {
            ApprovalSubjectSetting::query()->updateOrCreate(['subject_type' => 'PURCHASE_ORDER'], ['mode' => ApprovalMode::Required]);
            $policy = ApprovalPolicy::query()->create(['subject_type' => 'PURCHASE_ORDER', 'code' => 'p', 'version_no' => 1, 'name' => 'p', 'priority' => 0, 'status' => ApprovalPolicyStatus::Active, 'reason' => 't', 'changed_by' => $submitter]);
            $policy->steps()->create(['stage' => 1, 'name' => 'S1', 'required_approvals' => 1, 'permission' => self::APPROVE, 'use_authority_limit' => $limit, 'on_expiry' => $onExpiry, 'expires_after_hours' => 1]);

            return app(SubmitForApproval::class)('PURCHASE_ORDER', $po, User::query()->findOrFail($submitter))->id;
        });

        return compact('pdo', 'r', 'prefix', 'po', 'submitter', 'approver', 'request');
    }

    private function decide(array $f, int $as): \Closure
    {
        return fn () => app(DecideApproval::class)(ApprovalRequest::query()->findOrFail($f['request']), User::query()->findOrFail($as), ApprovalDecisionKind::Approved);
    }

    private function gate(array $f): string
    {
        return "select id from approval_requests where id = {$f['request']} for update";
    }

    public function test_control_nothing_changes(): void
    {
        $f = $this->fixture();
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->decide($f, $f['approver']), fn () => null);
            $this->assertTrue($o['success']);
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_authority_revoked(): void
    {
        $f = $this->fixture(true);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->decide($f, $f['approver']), fn ($h) => $h->exec("update approval_authorities set revoked_at = now() where user_id = {$f['approver']}"));
            $this->assertFalse($o['success']);
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    private function delegateFixture(): array
    {
        $f = $this->fixture(true);
        $f['delegate'] = $this->raceUser($f['pdo'], $f['r']['company'], $f['prefix'], 'delegate');
        $f['pdo']->exec("insert into approval_delegations (company_id, delegator_id, delegate_id, valid_from, valid_to, reason, created_by, created_at, updated_at) values ({$f['r']['company']}, {$f['approver']}, {$f['delegate']}, current_date - 1, current_date + 30, 't', {$f['approver']}, now(), now())");

        return $f;
    }

    public function test_delegation_control_and_revoked(): void
    {
        $f = $this->delegateFixture();
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->decide($f, $f['delegate']), fn ($h) => $h->exec("update approval_delegations set revoked_at = now() where delegate_id = {$f['delegate']}"));
            $this->assertFalse($o['success']);
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_delegation_control_ok(): void
    {
        $f = $this->delegateFixture();
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->decide($f, $f['delegate']), fn () => null);
            $this->assertTrue($o['success']);
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_delegator_suspended(): void
    {
        $f = $this->delegateFixture();
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->decide($f, $f['delegate']), fn ($h) => $h->exec("update users set status = 'SUSPENDED' where id = {$f['approver']}"));
            $this->assertFalse($o['success']);
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_delegator_permission_revoked(): void
    {
        $f = $this->delegateFixture();
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->decide($f, $f['delegate']), fn ($h) => $this->raceRevoke($h, $f['approver'], self::APPROVE));
            $this->assertFalse($o['success']);
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_document_edit_through_the_action_is_refused_while_pending(): void
    {
        $f = $this->fixture();
        try {
            $this->raceGrant($f['pdo'], $f['r']['company'], $f['submitter'], 'purchasing.purchase-order.create');
            $edit = null;
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->decide($f, $f['approver']), function () use ($f, &$edit): void {
                $edit = $this->raceCommitted(function () use ($f): string {
                    try {
                        app(UpdatePurchaseOrder::class)(PurchaseOrder::query()->findOrFail($f['po']), ['notes' => 'edited'], User::query()->findOrFail($f['submitter']));

                        return 'edited';
                    } catch (\Throwable $e) {
                        return 'refused: '.$e->getMessage();
                    }
                });
            });
            $this->assertStringStartsWith('refused', $edit);
            $this->assertTrue($o['success']);
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_stale_approval_cannot_be_consumed_by_issue(): void
    {
        $f = $this->fixture();
        try {
            $this->raceGrant($f['pdo'], $f['r']['company'], $f['submitter'], 'purchasing.purchase-order.issue');
            $this->raceAction($f['pdo'], $this->gate($f), $this->decide($f, $f['approver']), fn ($h) => $h->exec("update purchase_order_lines set unit_price = 9999, line_amount = 99990, base_unit_price = 9999, base_line_amount = 99990 where purchase_order_id = {$f['po']}"));
            $msg = $this->raceCommitted(function () use ($f): string {
                try {
                    app(IssuePurchaseOrder::class)(PurchaseOrder::query()->findOrFail($f['po']), User::query()->findOrFail($f['submitter']));

                    return 'ISSUED';
                } catch (\Throwable $e) {
                    return 'refused: '.$e->getMessage();
                }
            });
            $this->assertSame('DRAFT', $f['pdo']->query("select status from purchase_orders where id={$f['po']}")->fetchColumn(), 'the order stays a draft');
            $this->assertStringStartsWith('refused', $msg);
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }
}
