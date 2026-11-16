<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Jobs\Actions\AssessJobCloseout;
use App\Modules\Jobs\Actions\AssignJobResponsibility;
use App\Modules\Jobs\Actions\CloseJob;
use App\Modules\Jobs\Actions\CompleteJob;
use App\Modules\Jobs\Actions\StartJob;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\PreviewServiceRelease;
use App\Modules\Sales\Actions\RecordServiceCompletionBasis;
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
 * Phase 5 — the Sales fulfilment connection across two committed PostgreSQL sessions, through the REAL Sales release, receiver, source verifier and recorder. One session holds the
 * Sales fences and COMMITS a change of the agreed-conditions head (the source) while a release, a completion or a closeout waits on them: the waiting command re-reads the source
 * after the fences, so it is refused and writes no Job evidence, no fulfilment receipt and no release. A failure at the deferred COMMIT on the Sales receipt leaves nothing behind.
 */
class JobFulfilmentRaceTest extends TestCase
{
    use BuildsHandoverFixture;
    use RefreshDatabase;
    use ScopedReleaseFixtures;
    use TwoSessionRace;

    private const JOB_DUTIES = ['job.receive', 'job.assign', 'job.start', 'job.hold', 'job.hold.resolve', 'job.complete', 'job.close'];

    private const TABLES = ['service_jobs', 'job_events', 'job_completions', 'handover_fulfilment_receipts', 'job_closeouts', 'handover_release_events', 'job_operation_receipts'];

    protected function handoverDuties(): array
    {
        return [P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::QUOTATION_WITHDRAW, P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_PREPARE,
            P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE];
    }

    /** A committed company with a prepared service; optionally released and STARTED, completed and assessed. */
    private function fixture(bool $release = true, bool $complete = false, bool $assess = false): array
    {
        $pdo = $this->raceSession();
        $prefix = 'jf'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $worker = $this->raceUser($pdo, $r['company'], $prefix, 'worker');
        foreach ([...$this->handoverDuties(), ...self::JOB_DUTIES] as $duty) {
            $this->raceGrant($pdo, $r['company'], $actor, $duty);
        }
        $pdo->exec("update companies set timezone='Asia/Karachi' where id={$r['company']}");
        $this->raceBindCompany($r['company']);
        $this->activateHandover();
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest(array_map(fn (string $n): JobDuty => new JobDuty($n, JobDutyStatus::Active, 'op', 'projection', 'gate'), self::JOB_DUTIES)));
        $this->app->instance(QuotationTaxProvider::class, new FakeQuotationTaxProvider);
        $this->app->instance(QuotationControlsProvider::class, new FakeQuotationControlsProvider);
        $this->registerSourceFamilies();
        $data = $this->raceCommitted(function () use ($actor, $worker, $release, $complete, $assess): array {
            $user = User::findOrFail($actor);
            $party = (int) DB::table('parties')->where('company_id', (int) DB::table('users')->where('id', $actor)->value('company_id'))->value('id');
            $data = $this->serviceAcceptance($user, $party);
            $token = app(PreviewHandover::class)($user, $data['acceptance'], $data['inputs'])['preview_token'];
            app(PrepareHandoverPlan::class)($user, 'prepare', $data['acceptance'], $data['inputs'], $token);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $basis = $this->recordScopedBasis($user, 'basis', $data['acceptance'], $data['case']);
            $stage = (int) DB::table('handover_stages')->value('id');
            $preview = app(PreviewServiceRelease::class)($user, $stage);
            $out = ['stage' => $stage, 'acceptance' => $data['acceptance'], 'evidence' => (int) DB::table('customer_acceptances')->where('id', $data['acceptance'])->value('consent_evidence_id'),
                'basis' => $basis['basis_id'], 'fingerprint' => $preview['fingerprint'], 'generation' => 0, 'job' => 0];
            if (! $release) {
                return $out;
            }
            $released = app(ReleaseHandoverComponent::class)($user, 'release', $stage, $basis['basis_id'], $preview['fingerprint']);
            $job = (int) $released['job_id'];
            $assigned = app(AssignJobResponsibility::class)->execute($user, $job, $worker, 1, 'assign', JobLifecycleIntent::newCommandIdentity());
            $started = app(StartJob::class)->execute($user, $job, $assigned->generation, 'start', JobLifecycleIntent::newCommandIdentity(), 'Go');
            $out['job'] = $job;
            $out['generation'] = $started->generation;
            if ($complete) {
                $out['generation'] = app(CompleteJob::class)->execute($user, $job, $started->generation, 'complete', JobLifecycleIntent::newCommandIdentity(), 'Delivered.')->generation;
            }
            if ($assess) {
                $out['assessment'] = app(AssessJobCloseout::class)->execute($user, $job, $out['generation'], 'assess')->assessmentId;
            }

            return $out;
        });

        return $data + compact('pdo', 'r', 'actor', 'worker');
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
        $f['pdo']->exec('drop trigger if exists zz_commit_fail on handover_fulfilment_receipts');
        $f['pdo']->exec('drop function if exists zz_commit_fail()');
        $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
    }

    /** The source changes under the waiting command: a NEW head of the agreed-conditions chain (conditions only, so the scope declaration is gone and applicability is UNKNOWN). */
    private function changeSource(array $f): \Closure
    {
        return fn (PDO $h) => $this->onHolder($h, fn () => app(RecordServiceCompletionBasis::class)(User::findOrFail($f['actor']), 'source-change', $f['acceptance'], null, $f['evidence']));
    }

    public function test_a_source_change_that_commits_while_a_completion_waits_stops_the_completion_and_writes_no_receipt(): void
    {
        $f = $this->fixture();
        try {
            $before = $this->counts($f);
            $o = $this->raceAction($f['pdo'], $this->gate($f), fn () => app(CompleteJob::class)->execute(User::findOrFail($f['actor']), $f['job'], $f['generation'], 'late', JobLifecycleIntent::newCommandIdentity(), 'Delivered.'), $this->changeSource($f));
            $this->assertFalse($o['success'], 'the completion must not succeed past a source change that committed first');
            $this->assertSame($before['handover_fulfilment_receipts'], $this->rows($f, 'handover_fulfilment_receipts'));
            $this->assertSame(0, $this->rows($f, 'job_completions'));
            $this->assertSame('ACTIVE', $this->state($f));
            $this->assertSame($before['job_events'], $this->rows($f, 'job_events'), 'no event was recorded');
            $this->assertSame(2, $this->rows($f, 'service_completion_bases'), 'only the source change was recorded');
        } finally {
            $this->cleanup($f);
        }
    }

    public function test_a_source_change_that_commits_while_a_close_waits_stops_the_close(): void
    {
        $f = $this->fixture(complete: true, assess: true);
        try {
            $before = $this->counts($f);
            $o = $this->raceAction($f['pdo'], $this->gate($f), fn () => app(CloseJob::class)->execute(User::findOrFail($f['actor']), $f['job'], $f['generation'], $f['assessment'], 'late', JobLifecycleIntent::newCommandIdentity(), 'Closed.'), $this->changeSource($f));
            $this->assertFalse($o['success'], 'the close must not succeed on an assessment made before the source changed');
            $this->assertSame(0, $this->rows($f, 'job_closeouts'));
            $this->assertSame('COMPLETED', $this->state($f));
            $this->assertSame($before['handover_fulfilment_receipts'], $this->rows($f, 'handover_fulfilment_receipts'), 'the one receipt is untouched');
            $this->assertSame($before['job_events'], $this->rows($f, 'job_events'));
        } finally {
            $this->cleanup($f);
        }
    }

    public function test_a_source_change_that_commits_while_a_release_waits_stops_the_release(): void
    {
        $f = $this->fixture(release: false);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), fn () => app(ReleaseHandoverComponent::class)(User::findOrFail($f['actor']), 'late', $f['stage'], $f['basis'], $f['fingerprint']), $this->changeSource($f));
            $this->assertFalse($o['success'], 'a release reviewed before the source changed must not commit');
            $this->assertSame(0, $this->rows($f, 'handover_release_events'));
            $this->assertSame(0, $this->rows($f, 'service_jobs'));
            $this->assertSame(0, $this->rows($f, 'handover_fulfilment_receipts'));
        } finally {
            $this->cleanup($f);
        }
    }

    public function test_a_failure_at_the_deferred_commit_on_the_sales_receipt_leaves_the_job_started_and_nothing_else(): void
    {
        $f = $this->fixture();
        try {
            $before = $this->counts($f);
            $f['pdo']->exec("create function zz_commit_fail() returns trigger language plpgsql as \$\$ begin raise exception 'injected failure at commit' using errcode='P0001'; end \$\$");
            $f['pdo']->exec('create constraint trigger zz_commit_fail after insert on handover_fulfilment_receipts deferrable initially deferred for each row execute function zz_commit_fail()');
            $complete = fn (string $key) => fn () => app(CompleteJob::class)->execute(User::findOrFail($f['actor']), $f['job'], $f['generation'], $key, JobLifecycleIntent::newCommandIdentity(), 'Delivered.');
            $o = $this->raceAction($f['pdo'], $this->gate($f), $complete('doomed'), fn () => null);
            $this->assertFalse($o['success']);
            $this->assertStringContainsString('injected failure at commit', (string) $o['message']);
            $this->assertSame($before, $this->counts($f), 'a COMMIT-time failure on the Sales receipt rolls the Jobs completion back with it');
            $this->assertSame('ACTIVE', $this->state($f));
            $f['pdo']->exec('drop trigger zz_commit_fail on handover_fulfilment_receipts');
            $o = $this->raceAction($f['pdo'], $this->gate($f), $complete('retry'), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame(1, $this->rows($f, 'handover_fulfilment_receipts'));
            $this->assertSame(1, $this->rows($f, 'job_completions'));
        } finally {
            $this->cleanup($f);
        }
    }
}
