<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use App\Modules\Sales\Actions\AppendCustomerDecisionEvidence;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\PreviewServiceRelease;
use App\Modules\Sales\Actions\ReleaseHandoverComponent;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Feature\Modules\Sales\Concerns\BuildsHandoverFixture;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\Support\Sales\FakeQuotationControlsProvider;
use Tests\Support\Sales\FakeQuotationTaxProvider;
use Tests\Support\Sales\ScopedReleaseFixtures;
use Tests\TestCase;

/**
 * #129 — the owning-source actor eligibility of the Sales source verifier across two committed PostgreSQL sessions, through the REAL Sales release and receiver. The operator holds ONLY
 * a Jobs duty (no Sales duty). One session holds the fence the verifier needs while the verifier waits in a forked process; a revocation or suspension commits meanwhile; the
 * verifier must refuse AFTER the wait, before it proves or returns anything, and before any Job lock is taken.
 */
class JobSourceActorEligibilityRaceTest extends TestCase
{
    use BuildsHandoverFixture;
    use RefreshDatabase;
    use ScopedReleaseFixtures;
    use TwoSessionRace;

    private const JOB_DUTIES = ['job.receive', 'job.assign', 'job.start', 'job.hold', 'job.hold.resolve', 'job.complete', 'job.close'];

    protected function handoverDuties(): array
    {
        return [P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::QUOTATION_WITHDRAW, P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_PREPARE,
            P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE, P::CUSTOMER_DECISION_EVIDENCE_RECORD];
    }

    /** A committed company with a released Job, a releaser with every duty, and a Jobs-only operator holding exactly job.start. */
    private function fixture(): array
    {
        $pdo = $this->raceSession();
        $prefix = 'se'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $releaser = $this->raceUser($pdo, $r['company'], $prefix, 'releaser');
        $operator = $this->raceUser($pdo, $r['company'], $prefix, 'operator');
        foreach ([...$this->handoverDuties(), ...self::JOB_DUTIES] as $duty) {
            $this->raceGrant($pdo, $r['company'], $releaser, $duty);
        }
        $this->raceGrant($pdo, $r['company'], $operator, 'job.start');
        $pdo->exec("update companies set timezone='Asia/Karachi' where id={$r['company']}");
        $this->raceBindCompany($r['company']);
        $this->activateHandover();
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest(array_map(fn (string $n): JobDuty => new JobDuty($n, JobDutyStatus::Active, 'op', 'projection', 'gate'), self::JOB_DUTIES)));
        $this->app->instance(QuotationTaxProvider::class, new FakeQuotationTaxProvider);
        $this->app->instance(QuotationControlsProvider::class, new FakeQuotationControlsProvider);
        $this->registerSourceFamilies();
        $job = $this->raceCommitted(function () use ($releaser): int {
            $user = User::findOrFail($releaser);
            $party = (int) DB::table('parties')->where('company_id', (int) DB::table('users')->where('id', $releaser)->value('company_id'))->value('id');
            $data = $this->serviceAcceptance($user, $party);
            $token = app(PreviewHandover::class)($user, $data['acceptance'], $data['inputs'])['preview_token'];
            app(PrepareHandoverPlan::class)($user, 'prepare', $data['acceptance'], $data['inputs'], $token);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $basis = $this->recordScopedBasis($user, 'basis', $data['acceptance'], $data['case']);
            $stage = (int) DB::table('handover_stages')->value('id');
            $preview = app(PreviewServiceRelease::class)($user, $stage);

            return (int) app(ReleaseHandoverComponent::class)($user, 'release', $stage, $basis['basis_id'], $preview['fingerprint'])['job_id'];
        });

        return compact('pdo', 'r', 'releaser', 'operator', 'job');
    }

    private function enter(array $f): \Closure
    {
        return fn () => DB::transaction(fn () => app(JobExecutionContextVerifier::class)->enter(User::findOrFail($f['operator']), ServiceJob::findOrFail($f['job']), 'START'));
    }

    /** The company access barrier (the security writers' side) held while the verifier arrives. */
    private function fenceGate(): array
    {
        return ['select company_id from company_access_generations order by company_id for update'];
    }

    /** Only the Sales source fence: the verifier has already passed the company fence and its first look at the actor when it blocks here. */
    private function sourceGate(array $f): array
    {
        return ["select company_id from sales_authority_heads where company_id={$f['r']['company']} for update"];
    }

    private function jobEvents(array $f): int
    {
        return (int) $f['pdo']->query("select count(*) from job_events where job_id={$f['job']}")->fetchColumn();
    }

    public function test_control_a_jobs_only_operator_is_eligible_after_an_unchanged_gate(): void
    {
        $f = $this->fixture();
        try {
            foreach ([$this->fenceGate(), $this->sourceGate($f)] as $gate) {
                $o = $this->raceAction($f['pdo'], $gate, $this->enter($f), fn () => null);
                $this->assertTrue($o['success'], (string) $o['message']);
            }
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_revocation_or_suspension_committed_while_the_verifier_waits_at_the_company_fence_refuses(): void
    {
        foreach (['revoke', 'suspend'] as $change) {
            $f = $this->fixture();
            try {
                $before = $this->jobEvents($f);
                $o = $this->raceAction($f['pdo'], $this->fenceGate(), $this->enter($f), function (PDO $h) use ($f, $change): void {
                    $change === 'revoke' ? $this->raceRevoke($h, $f['operator'], 'job.start') : $h->exec("update users set status='SUSPENDED' where id={$f['operator']}");
                });
                $this->assertFalse($o['success'], $change);
                $this->assertStringContainsString('not permitted', (string) $o['message'], $change);
                $this->assertSame($before, $this->jobEvents($f));
            } finally {
                $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
            }
        }
    }

    public function test_a_change_that_bypasses_the_company_fence_is_still_refused_after_the_wait_for_the_source_fence(): void
    {
        // The verifier has already passed the company fence and judged the actor eligible when it blocks on the Sales authority head. A writer that did NOT use the company fence
        // (simulated by switching trigger enforcement off for the holder's session only) commits a revocation or suspension meanwhile: the SECOND, post-wait look must refuse it.
        foreach (['revoke', 'suspend'] as $change) {
            $f = $this->fixture();
            try {
                $o = $this->raceAction($f['pdo'], $this->sourceGate($f), $this->enter($f), function (PDO $h) use ($f, $change): void {
                    $h->exec('SET LOCAL session_replication_role = replica');
                    $change === 'revoke' ? $this->raceRevoke($h, $f['operator'], 'job.start') : $h->exec("update users set status='SUSPENDED' where id={$f['operator']}");
                });
                $this->assertFalse($o['success'], "{$change}: the post-wait recheck must refuse");
                $this->assertStringContainsString('not permitted', (string) $o['message'], $change);
            } finally {
                $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
            }
        }
    }

    /** The verifier answers as a failure the test can read from the forked child: the child's return value is discarded. */
    private function enterReady(array $f): \Closure
    {
        return function () use ($f): void {
            $ctx = DB::transaction(fn () => app(JobExecutionContextVerifier::class)->enter(User::findOrFail($f['operator']), ServiceJob::findOrFail($f['job']), 'START'));
            if (! $ctx->recordEligible) {
                throw new \RuntimeException('INELIGIBLE');
            }
            if (! $ctx->commandReady) {
                throw new \RuntimeException('NOT_READY');
            }
        };
    }

    /** The decision case row the verifier locks (after the authority head and the acceptance) when it takes the Sales source fences. */
    private function caseGate(array $f): array
    {
        $case = (int) $f['pdo']->query("select case_id from customer_acceptances where company_id={$f['r']['company']} order by id limit 1")->fetchColumn();

        return ["select id from customer_decision_cases where id={$case} for update"];
    }

    /** A correction of the head agreement evidence, committed by the holder session while the verifier waits on the case lock. */
    private function correctHeadEvidence(PDO $h, array $f): void
    {
        $head = $h->query("select evidence_id, (select case_id from customer_acceptances where company_id={$f['r']['company']} order by id limit 1) from service_completion_bases where company_id={$f['r']['company']} order by version desc limit 1")->fetch(PDO::FETCH_NUM);
        $h->exec("insert into customer_decision_evidence (company_id, case_id, predecessor_id, source_kind, authority_verification_basis, customer_representative_snapshot, occurrence_precision, factual_description, observation_state, recorded_by, recorded_at) values ({$f['r']['company']}, {$head[1]}, {$head[0]}, 'EMAIL', 'DOCUMENT_ON_FILE', 'Synthetic buyer', 'UNKNOWN', 'Correction: materials now required.', 'OBSERVED', {$f['releaser']}, now())");
    }

    public function test_control_the_job_is_ready_for_fresh_work_when_no_correction_arrives(): void
    {
        $f = $this->fixture();
        try {
            $o = $this->raceAction($f['pdo'], $this->caseGate($f), $this->enterReady($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_correction_committed_while_the_verifier_waits_on_the_source_locks_makes_fresh_work_not_ready_but_the_job_stays_eligible(): void
    {
        $f = $this->fixture();
        try {
            $events = $this->jobEvents($f);
            $o = $this->raceAction($f['pdo'], $this->caseGate($f), $this->enterReady($f), fn (PDO $h) => $this->correctHeadEvidence($h, $f));
            $this->assertFalse($o['success']);
            $this->assertSame('NOT_READY', $o['message'], 'eligible (not INELIGIBLE) but not ready: the correction committed before the post-lock proof is seen');
            $this->assertSame($events, $this->jobEvents($f), 'the verifier wrote nothing');
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    /**
     * The OPPOSITE order of the test above: a fresh authorised command obtains the Sales source locks FIRST (the real verifier, in an open transaction on its own connection), then the
     * customer's correction is requested through the real Action and must WAIT on the case lock. Serialised correctly: the command decides on the evidence as it stood (ready), the
     * correction applies only after the command commits, the historical observation is kept exactly as it was, and every LATER fresh command is refused (not ready) while the Job
     * stays eligible. Nothing is cancelled, reopened or recovered.
     */
    public function test_a_fresh_command_holding_the_source_locks_first_makes_the_correction_wait_and_later_fresh_work_is_refused(): void
    {
        $f = $this->fixture();
        $company = $f['r']['company'];
        $q = fn (string $sql) => $f['pdo']->query($sql)->fetchColumn();
        try {
            $case = (int) $q("select case_id from customer_acceptances where company_id={$company} order by id limit 1");
            $head = (int) $q("select evidence_id from service_completion_bases where company_id={$company} order by version desc limit 1");
            $generation = (int) $q("select generation from customer_decision_cases where id={$case}");
            $originalText = (string) $q("select factual_description from customer_decision_evidence where id={$head}");
            $observations = (int) $q("select count(*) from customer_decision_evidence where case_id={$case}");
            $events = $this->jobEvents($f);

            // 1. The fresh command takes the source locks first, on its own connection and transaction, and judges the evidence as it stands: ready.
            $original = config('database.default');
            config(['database.connections.race_fresh' => config('database.connections.testing'), 'database.default' => 'race_fresh']);
            DB::beginTransaction();
            $context = app(JobExecutionContextVerifier::class)->enter(User::findOrFail($f['operator']), ServiceJob::findOrFail($f['job']), 'START');
            $this->assertTrue($context->recordEligible);
            $this->assertTrue($context->commandReady, 'ready on the evidence as it stood when the command took its locks');

            // 2. The customer's correction, through the real Action, in a forked process that must queue behind those locks.
            $result = sys_get_temp_dir().'/sec_race_corr_'.getmypid().'_'.bin2hex(random_bytes(3)).'.json';
            @unlink($result);
            $pid = pcntl_fork();
            if ($pid === -1) {
                DB::rollBack();
                $this->fail('Unable to fork a process for the race test.');
            }
            if ($pid === 0) {
                config(['database.connections.race_child' => config('database.connections.testing'), 'database.default' => 'race_child']);
                try {
                    app(AppendCustomerDecisionEvidence::class)(User::findOrFail($f['releaser']), 'correct-race', $case, $generation, [
                        'source_kind' => 'EMAIL', 'authority_verification_basis' => 'DOCUMENT_ON_FILE', 'customer_representative_snapshot' => 'Synthetic buyer',
                        'occurrence_precision' => 'UNKNOWN', 'factual_description' => 'Correction: the customer now wants materials supplied by us.',
                    ], $head);
                    file_put_contents($result, json_encode(['success' => true]));
                } catch (\Throwable $e) {
                    file_put_contents($result, json_encode(['success' => false, 'message' => $e->getMessage()]));
                }
                posix_kill(getmypid(), SIGKILL);
            }
            $monitor = $this->raceSession();
            $blocked = false;
            for ($i = 0; $i < 400 && ! $blocked; $i++) {
                $blocked = (int) $monitor->query("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and pid <> pg_backend_pid()")->fetchColumn() > 0;
                $blocked || usleep(25_000);
            }
            if (! $blocked) {
                DB::rollBack();
                config(['database.default' => $original]);
                pcntl_waitpid($pid, $status);
                $this->fail('the correction never reached a lock wait; the race could not be staged: '.(string) @file_get_contents($result));
            }

            // 3. While the command still holds its locks, the correction has not happened.
            $this->assertFileDoesNotExist($result, 'the correction must still be waiting');
            $this->assertSame($observations, (int) $monitor->query("select count(*) from customer_decision_evidence where case_id={$case}")->fetchColumn());

            // 4. The command finishes and commits; only then does the correction proceed.
            DB::commit();
            config(['database.default' => $original]);
            DB::purge('race_fresh');
            pcntl_waitpid($pid, $status);
            $outcome = json_decode((string) file_get_contents($result), true);
            @unlink($result);
            $this->assertTrue($outcome['success'] ?? false, 'the correction applies after the command: '.json_encode($outcome));

            // 5. History is preserved exactly; the correction is appended, never an overwrite.
            $this->assertSame($observations + 1, (int) $q("select count(*) from customer_decision_evidence where case_id={$case}"));
            $this->assertSame($originalText, (string) $q("select factual_description from customer_decision_evidence where id={$head}"));
            $this->assertNull($q("select predecessor_id from customer_decision_evidence where id={$head}") ?: null);
            $this->assertSame($head, (int) $q("select predecessor_id from customer_decision_evidence where case_id={$case} order by id desc limit 1"));
            $this->assertSame($events, $this->jobEvents($f), 'nothing was cancelled, reopened or written to the Job');

            // 6. Every LATER fresh command is refused (not ready), while the Job stays eligible for safe operations.
            $later = $this->raceCommitted(fn () => DB::transaction(fn () => app(JobExecutionContextVerifier::class)->enter(User::findOrFail($f['operator']), ServiceJob::findOrFail($f['job']), 'START')));
            $this->assertTrue($later->recordEligible);
            $this->assertFalse($later->commandReady, 'superseded agreement no longer supports fresh work');
        } finally {
            if (DB::transactionLevel() > 0 && config('database.default') === 'race_fresh') {
                DB::rollBack();
            }
            $this->purgeRaceCompany($f['pdo'], $company);
        }
    }
}
