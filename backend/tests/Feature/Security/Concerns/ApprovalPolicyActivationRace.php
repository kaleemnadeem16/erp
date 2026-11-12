<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Concerns;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Shared helpers for the ADR 0164 policy-activation-versus-decision/issue races. Needs {@see TwoSessionRace}.
 *
 * Activation is the same statement pair ActivateApprovalPolicy runs (supersede the live version, then make the draft live), issued as raw SQL so a test session can
 * hold it inside its own transaction; both statements pass through the statement-level writer barrier on approval_policies exactly as the Action's do.
 */
trait ApprovalPolicyActivationRace
{
    /** Activation statements for a holder transaction: it already owns the fences, the barrier re-takes them without waiting. */
    private function activatePolicy(PDO $session, int $supersededId, int $draftId): void
    {
        $session->exec("update approval_policies set status = 'SUPERSEDED', effective_to = current_date where id = {$supersededId}");
        $session->exec("update approval_policies set status = 'ACTIVE', effective_from = current_date, effective_to = null where id = {$draftId}");
    }

    /** Child-process setup: any lock wait longer than this fails the scenario instead of hanging it. */
    private function shortChildLockTimeout(): void
    {
        DB::statement("SET lock_timeout = '5s'");
    }

    /**
     * Child-process listener: the moment the action takes its approval-request row lock (it then already owns every fence above it), a second real session tries to
     * activate the policy and must be refused by lock timeout (55P03), not slip in and not deadlock (40P01).
     */
    private function activationMustWaitForActionOnceRequestLocked(int $supersededId, int $draftId): void
    {
        $checked = false;
        DB::listen(function ($query) use (&$checked, $supersededId, $draftId): void {
            if ($checked || ! str_contains($query->sql, 'approval_requests') || ! str_contains($query->sql, 'for update')) {
                return;
            }
            $checked = true;
            $writer = $this->raceSession();
            $writer->exec("SET lock_timeout = '200ms'");
            try {
                $writer->exec("update approval_policies set status = 'SUPERSEDED', effective_to = current_date where id = {$supersededId}");
            } catch (\PDOException $e) {
                if ($e->getCode() !== '55P03') {
                    throw new \RuntimeException('policy activation failed with '.$e->getCode().' instead of waiting: '.$e->getMessage());
                }

                return;
            }
            throw new \RuntimeException('policy activation did not wait for the action that holds the fences');
        });
    }

    /** @param array{success: bool, message: string|null, class: string|null} $outcome */
    private function assertNoDeadlockOrTimeout(array $outcome): void
    {
        $this->assertDoesNotMatchRegularExpression('/deadlock|40P01|55P03|lock timeout|canceling statement/i', (string) $outcome['message'], json_encode($outcome));
    }

    /** @return array{active: list<int>, superseded: list<int>} */
    private function policyStates(PDO $pdo, string $subjectType, string $code): array
    {
        $rows = $pdo->query("select id, status from approval_policies where subject_type = '{$subjectType}' and code = '{$code}' order by id")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'active' => array_values(array_map(fn ($r): int => (int) $r['id'], array_filter($rows, fn ($r): bool => $r['status'] === 'ACTIVE'))),
            'superseded' => array_values(array_map(fn ($r): int => (int) $r['id'], array_filter($rows, fn ($r): bool => $r['status'] === 'SUPERSEDED'))),
        ];
    }
}
