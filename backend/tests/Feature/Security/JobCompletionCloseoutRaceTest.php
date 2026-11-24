<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Jobs\Actions\AssessJobCloseout;
use App\Modules\Jobs\Actions\AssignJobResponsibility;
use App\Modules\Jobs\Actions\CloseJob;
use App\Modules\Jobs\Actions\CompleteJob;
use App\Modules\Jobs\Actions\PlaceJobHold;
use App\Modules\Jobs\Actions\StartJob;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
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
 * Phase 5 — Job completion and closeout across two committed PostgreSQL sessions, through the REAL Sales release, receiver and source verifier. One session holds the Sales fences
 * while another command waits on them (source before Job lock), then a competing command or an authority change commits: simultaneous completes, completes against holds,
 * closes against closes, a revocation while a completion or close waits, and a failure at the deferred COMMIT each leave exactly the right evidence, never a duplicate.
 */
class JobCompletionCloseoutRaceTest extends TestCase
{
    use BuildsHandoverFixture;
    use RefreshDatabase;
    use ScopedReleaseFixtures;
    use TwoSessionRace;

    private const JOB_DUTIES = ['job.receive', 'job.assign', 'job.start', 'job.hold', 'job.hold.resolve', 'job.complete', 'job.close'];

    private const TABLES = ['service_jobs', 'job_events', 'job_completions', 'job_closeout_assessments', 'job_closeouts', 'job_operation_receipts'];

    protected function handoverDuties(): array
    {
        return [P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::QUOTATION_WITHDRAW, P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_PREPARE,
            P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE];
    }

    /** A committed company with a released, assigned and STARTED Job, and two actors who may do everything on it. */
    private function fixture(bool $complete = false, bool $assess = false): array
    {
        $pdo = $this->raceSession();
        $prefix = 'jc'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $other = $this->raceUser($pdo, $r['company'], $prefix, 'other');
        $worker = $this->raceUser($pdo, $r['company'], $prefix, 'worker');
        foreach ([$actor, $other] as $who) {
            foreach ([...$this->handoverDuties(), ...self::JOB_DUTIES] as $duty) {
                $this->raceGrant($pdo, $r['company'], $who, $duty);
            }
        }
        $pdo->exec("update companies set timezone='Asia/Karachi' where id={$r['company']}");
        $this->raceBindCompany($r['company']);
        $this->activateHandover();
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest(array_map(fn (string $n): JobDuty => new JobDuty($n, JobDutyStatus::Active, 'op', 'projection', 'gate'), self::JOB_DUTIES)));
        $this->app->instance(QuotationTaxProvider::class, new FakeQuotationTaxProvider);
        $this->app->instance(QuotationControlsProvider::class, new FakeQuotationControlsProvider);
        $this->registerSourceFamilies();
        $data = $this->raceCommitted(function () use ($actor, $worker, $complete, $assess): array {
            $user = User::findOrFail($actor);
            $party = (int) DB::table('parties')->where('company_id', (int) DB::table('users')->where('id', $actor)->value('company_id'))->value('id');
            $data = $this->serviceAcceptance($user, $party);
            $token = app(PreviewHandover::class)($user, $data['acceptance'], $data['inputs'])['preview_token'];
            app(PrepareHandoverPlan::class)($user, 'prepare', $data['acceptance'], $data['inputs'], $token);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $basis = $this->recordScopedBasis($user, 'basis', $data['acceptance'], $data['case']);
            $stage = (int) DB::table('handover_stages')->value('id');
            $preview = app(PreviewServiceRelease::class)($user, $stage);
            $released = app(ReleaseHandoverComponent::class)($user, 'release', $stage, $basis['basis_id'], $preview['fingerprint']);
            $job = (int) $released['job_id'];
            $assigned = app(AssignJobResponsibility::class)->execute($user, $job, $user->id, 1, 'assign', JobLifecycleIntent::newCommandIdentity());
            $started = app(StartJob::class)->execute($user, $job, $assigned->generation, 'start', JobLifecycleIntent::newCommandIdentity(), 'Go');
            $out = ['job' => $job, 'generation' => $started->generation];
            if ($complete) {
                $done = app(CompleteJob::class)->execute($user, $job, $started->generation, 'complete', JobLifecycleIntent::newCommandIdentity(), 'Delivered.');
                $out['generation'] = $done->generation;
            }
            if ($assess) {
                $a = app(AssessJobCloseout::class)->execute($user, $job, $out['generation'], 'assess');
                $out['assessment'] = $a->assessmentId;
            }

            return $out;
        });

        return $data + compact('pdo', 'r', 'actor', 'other', 'worker');
    }

    private function gate(array $f): array
    {
        return ['select company_id from company_access_generations order by company_id for update', "select company_id from sales_authority_heads where company_id={$f['r']['company']} for update"];
    }

    private function rows(array $f, string $table, string $where = ''): int
    {
        $extra = $where === '' ? '' : " and {$where}";

        return (int) $f['pdo']->query("select count(*) from {$table} where company_id={$f['r']['company']}{$extra}")->fetchColumn();
    }

    /** @return array<string,int> */
    private function counts(array $f): array
    {
        return array_combine(self::TABLES, array_map(fn (string $t): int => $this->rows($f, $t), self::TABLES));
    }

    private function state(array $f): string
    {
        return (string) $f['pdo']->query("select state from service_jobs where id={$f['job']}")->fetchColumn();
    }

    private function onHolder(PDO $holder, callable $work): void
    {
        $original = config('database.default');
        config(['database.connections.race_holder' => config('database.connections.testing'), 'database.default' => 'race_holder']);
        DB::connection()->setPdo($holder);
        (new \ReflectionProperty(DB::connection(), 'transactions'))->setValue(DB::connection(), 1);
        try {
            $work();
        } finally {
            config(['database.default' => $original]);
            DB::purge('race_holder');
        }
    }

    private function cleanup(array $f): void
    {
        $f['pdo']->exec('drop trigger if exists zz_commit_fail on job_completions');
        $f['pdo']->exec('drop trigger if exists zz_commit_fail_k on job_closeouts');
        $f['pdo']->exec('drop function if exists zz_commit_fail()');
        $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
    }

    private function complete(array $f, string $key, ?string $identity = null, string $who = 'actor', ?int $generation = null): \Closure
    {
        $identity ??= JobLifecycleIntent::newCommandIdentity();

        return fn () => app(CompleteJob::class)->execute(User::findOrFail($f[$who]), $f['job'], $generation ?? $f['generation'], $key, $identity, 'Delivered.');
    }

    private function close(array $f, string $key, ?string $identity = null, string $who = 'actor'): \Closure
    {
        $identity ??= JobLifecycleIntent::newCommandIdentity();

        return fn () => app(CloseJob::class)->execute(User::findOrFail($f[$who]), $f['job'], $f['generation'], $f['assessment'], $key, $identity, 'Closed.');
    }

    public function test_control_a_completion_after_an_unchanged_gate_completes_the_job_once(): void
    {
        $f = $this->fixture();
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->complete($f, 'only'), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame('COMPLETED', $this->state($f));
            $this->assertSame(1, $this->rows($f, 'job_completions'));
        } finally {
            $this->cleanup($f);
        }
    }

    public function test_two_simultaneous_completions_complete_the_job_exactly_once(): void
    {
        // The same business command by a fresh key and a second actor resolves to the one completion; a different command is refused. Never two completions.
        foreach (['same' => true, 'different' => false] as $label => $sameCommand) {
            $f = $this->fixture();
            try {
                $identity = JobLifecycleIntent::newCommandIdentity();
                $o = $this->raceAction($f['pdo'], $this->gate($f), $this->complete($f, 'second', $sameCommand ? $identity : null, 'other'), fn (PDO $h) => $this->onHolder($h, $this->complete($f, 'first', $identity)));
                $this->assertSame($sameCommand, $o['success'], $label.': '.(string) $o['message']);
                $this->assertSame(1, $this->rows($f, 'job_completions'), $label);
                $this->assertSame(1, $this->rows($f, 'job_events', "operation='COMPLETE'"), $label);
                $this->assertSame('COMPLETED', $this->state($f));
            } finally {
                $this->cleanup($f);
            }
        }
    }

    public function test_a_hold_that_commits_while_a_completion_waits_stops_the_completion(): void
    {
        $f = $this->fixture();
        try {
            $before = $this->counts($f);
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->complete($f, 'late'), fn (PDO $h) => $this->onHolder($h, fn () => app(PlaceJobHold::class)->execute(
                User::findOrFail($f['actor']), $f['job'], 'Customer asked us to wait', $f['generation'], 'hold', JobLifecycleIntent::newCommandIdentity(),
            )));
            $this->assertFalse($o['success'], 'the completion must not succeed past a hold that committed first');
            $this->assertSame(0, $this->rows($f, 'job_completions'));
            $this->assertSame('ACTIVE', $this->state($f));
            $this->assertSame($before['job_events'] + 1, $this->rows($f, 'job_events'), 'only the hold was recorded');
        } finally {
            $this->cleanup($f);
        }
    }

    public function test_two_simultaneous_closes_close_the_job_exactly_once(): void
    {
        foreach (['same' => true, 'different' => false] as $label => $sameCommand) {
            $f = $this->fixture(complete: true, assess: true);
            try {
                $identity = JobLifecycleIntent::newCommandIdentity();
                $o = $this->raceAction($f['pdo'], $this->gate($f), $this->close($f, 'second', $sameCommand ? $identity : null, 'other'), fn (PDO $h) => $this->onHolder($h, $this->close($f, 'first', $identity)));
                $this->assertSame($sameCommand, $o['success'], $label.': '.(string) $o['message']);
                $this->assertSame(1, $this->rows($f, 'job_closeouts'), $label);
                $this->assertSame(1, $this->rows($f, 'job_events', "operation='CLOSE'"), $label);
                $this->assertSame('CLOSED', $this->state($f));
            } finally {
                $this->cleanup($f);
            }
        }
    }

    public function test_revoking_the_duty_or_suspending_the_actor_while_a_completion_or_close_waits_refuses_before_any_write(): void
    {
        foreach (['complete' => ['job.complete', false], 'close' => ['job.close', true]] as $label => [$duty, $closing]) {
            foreach (['revoke', 'suspend'] as $change) {
                $f = $this->fixture(complete: $closing, assess: $closing);
                try {
                    $before = $this->counts($f);
                    $action = $closing ? $this->close($f, 'late') : $this->complete($f, 'late');
                    $o = $this->raceAction($f['pdo'], $this->gate($f), $action, function (PDO $h) use ($f, $duty, $change): void {
                        if ($change === 'revoke') {
                            $this->raceRevoke($h, $f['actor'], $duty);
                        } else {
                            $h->exec("update users set status='SUSPENDED' where id={$f['actor']}");
                        }
                    });
                    $this->assertFalse($o['success'], "{$label}/{$change}");
                    $this->assertStringContainsString('not permitted', (string) $o['message'], "{$label}/{$change}");
                    $this->assertSame($before, $this->counts($f), "{$label}/{$change}: nothing was written");
                } finally {
                    $this->cleanup($f);
                }
            }
        }
    }

    public function test_a_replay_waiting_on_the_fence_is_refused_after_the_duty_is_revoked(): void
    {
        $f = $this->fixture();
        try {
            $identity = JobLifecycleIntent::newCommandIdentity();
            $first = $this->complete($f, 'k', $identity);
            $this->raceCommitted($first);
            $before = $this->counts($f);
            $replay = fn () => app(CompleteJob::class)->execute(User::findOrFail($f['actor']), $f['job'], $f['generation'], 'k', $identity, 'Delivered.');
            $o = $this->raceAction($f['pdo'], $this->gate($f), $replay, fn (PDO $h) => $this->raceRevoke($h, $f['actor'], 'job.complete'));
            $this->assertFalse($o['success'], 'a revoked duty must refuse a waiting replay, not return the original');
            $this->assertStringContainsString('not permitted', (string) $o['message']);
            $this->assertSame($before, $this->counts($f));
        } finally {
            $this->cleanup($f);
        }
    }

    public function test_a_failure_at_the_deferred_commit_leaves_the_job_started_and_nothing_else(): void
    {
        $f = $this->fixture();
        try {
            $before = $this->counts($f);
            $f['pdo']->exec("create function zz_commit_fail() returns trigger language plpgsql as \$\$ begin raise exception 'injected failure at commit' using errcode='P0001'; end \$\$");
            $f['pdo']->exec('create constraint trigger zz_commit_fail after insert on job_completions deferrable initially deferred for each row execute function zz_commit_fail()');
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->complete($f, 'doomed'), fn () => null);
            $this->assertFalse($o['success']);
            $this->assertStringContainsString('injected failure at commit', (string) $o['message']);
            $this->assertSame($before, $this->counts($f), 'a COMMIT-time failure leaves nothing behind');
            $this->assertSame('ACTIVE', $this->state($f));
            $f['pdo']->exec('drop trigger zz_commit_fail on job_completions');
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->complete($f, 'retry'), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame(1, $this->rows($f, 'job_completions'));
        } finally {
            $this->cleanup($f);
        }

        $f = $this->fixture(complete: true, assess: true);
        try {
            $before = $this->counts($f);
            $f['pdo']->exec("create function zz_commit_fail() returns trigger language plpgsql as \$\$ begin raise exception 'injected failure at commit' using errcode='P0001'; end \$\$");
            $f['pdo']->exec('create constraint trigger zz_commit_fail_k after insert on job_closeouts deferrable initially deferred for each row execute function zz_commit_fail()');
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->close($f, 'doomed'), fn () => null);
            $this->assertFalse($o['success']);
            $this->assertSame($before, $this->counts($f), 'a COMMIT-time failure leaves no closeout');
            $this->assertSame('COMPLETED', $this->state($f));
        } finally {
            $this->cleanup($f);
        }
    }
}
