<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\PreviewServiceRelease;
use App\Modules\Sales\Actions\ReleaseHandoverComponent;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Sales\Concerns\BuildsHandoverFixture;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\Support\Sales\FakeQuotationControlsProvider;
use Tests\Support\Sales\FakeQuotationTaxProvider;
use Tests\Support\Sales\ScopedReleaseFixtures;
use Tests\TestCase;

/**
 * Phase 5 Batch 1 — the REAL Sales release coordinator and the REAL Jobs receiver across two committed PostgreSQL sessions: simultaneous releases (fresh key or a second actor) create
 * exactly one Job, one number, one baseline, component, activity and origin; a failure at the deferred COMMIT leaves nothing at all; revoking `job.receive` or suspending the actor
 * while a release waits refuses it before any write.
 */
class JobReceiverReleaseRaceTest extends TestCase
{
    use BuildsHandoverFixture;
    use RefreshDatabase;
    use ScopedReleaseFixtures;
    use TwoSessionRace;

    private const TABLES = ['handover_release_events', 'service_jobs', 'job_events', 'job_baselines', 'job_handover_origins', 'job_sales_origins', 'job_baseline_components', 'job_activities', 'numbering_allocations'];

    protected function handoverDuties(): array
    {
        return [P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::QUOTATION_WITHDRAW, P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_PREPARE,
            P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE];
    }

    private function fixture(): array
    {
        $pdo = $this->raceSession();
        $prefix = 'jr'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $other = $this->raceUser($pdo, $r['company'], $prefix, 'other');
        foreach ([$actor, $other] as $who) {
            foreach ([...$this->handoverDuties(), 'job.receive'] as $duty) {
                $this->raceGrant($pdo, $r['company'], $who, $duty);
            }
        }
        $pdo->exec("update companies set timezone='Asia/Karachi' where id={$r['company']}");
        $this->raceBindCompany($r['company']);
        $this->activateHandover();
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest([new JobDuty('job.receive', JobDutyStatus::Active, 'op', 'projection', 'gate')]));
        $this->app->instance(QuotationTaxProvider::class, new FakeQuotationTaxProvider);
        $this->app->instance(QuotationControlsProvider::class, new FakeQuotationControlsProvider);
        $this->registerSourceFamilies();
        $data = $this->raceCommitted(function () use ($actor): array {
            $user = User::findOrFail($actor);
            $party = (int) DB::table('parties')->where('company_id', (int) DB::table('users')->where('id', $actor)->value('company_id'))->value('id');
            $data = $this->serviceAcceptance($user, $party);
            $token = app(PreviewHandover::class)($user, $data['acceptance'], $data['inputs'])['preview_token'];
            app(PrepareHandoverPlan::class)($user, 'prepare', $data['acceptance'], $data['inputs'], $token);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $basis = $this->recordScopedBasis($user, 'basis', $data['acceptance'], $data['case']);
            $stage = (int) DB::table('handover_stages')->value('id');
            $preview = app(PreviewServiceRelease::class)($user, $stage);

            return $data + ['stage' => $stage, 'basis' => $basis['basis_id'], 'fingerprint' => $preview['fingerprint'], 'ready' => $preview['ready']];
        });

        return $data + compact('pdo', 'r', 'actor', 'other');
    }

    private function release(array $f, string $key, string $who = 'actor'): \Closure
    {
        return fn () => app(ReleaseHandoverComponent::class)(User::findOrFail($f[$who]), $key, $f['stage'], $f['basis'], $f['fingerprint']);
    }

    private function gate(array $f): array
    {
        return ['select company_id from company_access_generations order by company_id for update', "select company_id from sales_authority_heads where company_id={$f['r']['company']} for update"];
    }

    private function rows(array $f, string $table): int
    {
        // Numbers of other documents (the quotation) are not Job numbers: count only the Job series.
        $extra = $table === 'numbering_allocations' ? " and doc_type='JOB'" : '';

        return (int) $f['pdo']->query("select count(*) from {$table} where company_id={$f['r']['company']}{$extra}")->fetchColumn();
    }

    /** @return array<string,int> */
    private function counts(array $f): array
    {
        return array_combine(self::TABLES, array_map(fn (string $t): int => $this->rows($f, $t), self::TABLES));
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
        $f['pdo']->exec('drop trigger if exists zz_commit_fail on job_activities');
        $f['pdo']->exec('drop function if exists zz_commit_fail()');
        $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
    }

    private function assertOneJobEverywhere(array $f): void
    {
        foreach (['handover_release_events', 'service_jobs', 'job_events', 'job_baselines', 'job_handover_origins', 'job_sales_origins', 'job_baseline_components', 'job_activities'] as $table) {
            $this->assertSame(1, $this->rows($f, $table), "exactly one row in {$table}");
        }
        $this->assertSame(1, $this->rows($f, 'numbering_allocations'), 'exactly one number was allocated');
    }

    public function test_control_a_release_after_an_unchanged_gate_creates_one_job_with_all_its_evidence(): void
    {
        $f = $this->fixture();
        try {
            $this->assertTrue($f['ready']);
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->release($f, 'only'), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertOneJobEverywhere($f);
        } finally {
            $this->cleanup($f);
        }
    }

    public function test_two_simultaneous_releases_by_a_fresh_key_or_a_second_actor_create_exactly_one_job_and_one_number(): void
    {
        foreach (['actor', 'other'] as $second) {
            $f = $this->fixture();
            try {
                $o = $this->raceAction($f['pdo'], $this->gate($f), $this->release($f, 'second', $second), fn (PDO $h) => $this->onHolder($h, $this->release($f, 'first')));
                $this->assertTrue($o['success'], (string) $o['message']);
                $this->assertOneJobEverywhere($f);
                $this->assertSame(1, $this->rows($f, 'customer_acceptances'), 'the release consumed no second acceptance');
            } finally {
                $this->cleanup($f);
            }
        }
    }

    public function test_a_failure_at_the_deferred_commit_leaves_no_release_no_job_and_no_number(): void
    {
        $f = $this->fixture();
        try {
            $before = $this->counts($f);
            $f['pdo']->exec("create function zz_commit_fail() returns trigger language plpgsql as \$\$ begin raise exception 'injected failure at commit' using errcode='P0001'; end \$\$");
            $f['pdo']->exec('create constraint trigger zz_commit_fail after insert on job_activities deferrable initially deferred for each row execute function zz_commit_fail()');
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->release($f, 'doomed'), fn () => null);
            $this->assertFalse($o['success']);
            $this->assertStringContainsString('injected failure at commit', (string) $o['message']);
            $this->assertSame($before, $this->counts($f), 'a COMMIT-time failure leaves nothing behind');
            $f['pdo']->exec('drop trigger zz_commit_fail on job_activities');
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->release($f, 'retry'), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertOneJobEverywhere($f);
        } finally {
            $this->cleanup($f);
        }
    }

    public function test_revoking_job_receive_or_suspending_the_actor_while_waiting_refuses_before_any_write(): void
    {
        foreach (['revoke', 'suspend'] as $change) {
            $f = $this->fixture();
            try {
                $before = $this->counts($f);
                $o = $this->raceAction($f['pdo'], $this->gate($f), $this->release($f, 'late'), function (PDO $h) use ($f, $change): void {
                    if ($change === 'revoke') {
                        $this->raceRevoke($h, $f['actor'], 'job.receive');
                    } else {
                        $h->exec("update users set status='SUSPENDED' where id={$f['actor']}");
                    }
                });
                $this->assertFalse($o['success']);
                $this->assertStringContainsString('not permitted', (string) $o['message']);
                $this->assertSame($before, $this->counts($f));
            } finally {
                $this->cleanup($f);
            }
        }
    }

    public function test_revoking_the_jobs_and_the_sales_duty_at_once_while_waiting_refuses_before_any_write(): void
    {
        $f = $this->fixture();
        try {
            $before = $this->counts($f);
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->release($f, 'late'), function (PDO $h) use ($f): void {
                $this->raceRevoke($h, $f['actor'], 'job.receive');
                $this->raceRevoke($h, $f['actor'], P::HANDOVER_RELEASE);
            });
            $this->assertFalse($o['success']);
            $this->assertStringContainsString('not permitted', (string) $o['message']);
            $this->assertSame($before, $this->counts($f));
        } finally {
            $this->cleanup($f);
        }
    }

    public function test_a_replay_after_the_receiving_duty_was_revoked_is_refused_and_a_race_never_touches_the_claims(): void
    {
        $f = $this->fixture();
        try {
            $claims = $this->claimSnapshot($f);
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->release($f, 'second', 'other'), fn (PDO $h) => $this->onHolder($h, $this->release($f, 'first')));
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertOneJobEverywhere($f);
            $this->assertSame($claims, $this->claimSnapshot($f), 'two simultaneous releases leave every claim head, acceptance and accepted line byte-equal');

            $before = $this->counts($f);
            $this->raceRevoke($f['pdo'], $f['actor'], 'job.receive');
            // Refused before any lock is taken, so there is nothing to stage: call it directly against the committed state.
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            try {
                ($this->release($f, 'first'))();
                $this->fail('a replay by an actor whose job.receive was revoked must not succeed');
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString('not permitted', $e->getMessage());
            }
            $this->assertSame($before, $this->counts($f));
            $this->assertSame($claims, $this->claimSnapshot($f));
        } finally {
            $this->cleanup($f);
        }
    }

    public function test_a_replay_waiting_on_the_access_fence_is_refused_when_job_receive_is_revoked_or_the_actor_suspended_meanwhile(): void
    {
        // Review P1: the receiving duty is judged at the decision point INSIDE the locked transaction. Session B replays an existing release and WAITS on the fence the holder
        // session owns; the holder revokes job.receive (or suspends the member) and commits; B must refuse and return the stored Job reference to nobody.
        foreach ([['exact-key replay', 'first', 'actor'], ['business replay by a fresh key', 'fresh', 'actor'], ['business replay by a second actor', 'other-key', 'other']] as [$label, $key, $who]) {
            foreach (['revoke', 'suspend'] as $change) {
                $f = $this->fixture();
                try {
                    // Setup exactly as the existing replay test: the holder session commits release 'first'; 'second' (by the other actor) waits and resolves to it.
                    $setup = $this->raceAction($f['pdo'], $this->gate($f), $this->release($f, 'second', 'other'), fn (PDO $h) => $this->onHolder($h, $this->release($f, 'first')));
                    $this->assertTrue($setup['success'], (string) $setup['message']);
                    $this->assertOneJobEverywhere($f);
                    $before = $this->counts($f);
                    $claims = $this->claimSnapshot($f);
                    $o = $this->raceAction($f['pdo'], $this->gate($f), $this->release($f, $key, $who), function (PDO $h) use ($f, $change, $who): void {
                        if ($change === 'revoke') {
                            $this->raceRevoke($h, $f[$who], 'job.receive');
                        } else {
                            $h->exec("update users set status='SUSPENDED' where id={$f[$who]}");
                        }
                    });
                    $this->assertFalse($o['success'], "{$label} ({$change}) must be refused, not replayed");
                    $this->assertStringContainsString('not permitted', (string) $o['message']);
                    $this->assertSame($before, $this->counts($f), "{$label} ({$change}) wrote nothing");
                    $this->assertSame($claims, $this->claimSnapshot($f));
                } finally {
                    $this->cleanup($f);
                }
            }
        }
    }

    /** @return array<string,string> */
    private function claimSnapshot(array $f): array
    {
        $company = $f['r']['company'];
        $out = [];
        foreach (['customer_scope_claim_heads', 'customer_acceptances', 'customer_acceptance_lines'] as $table) {
            $out[$table] = md5(json_encode($f['pdo']->query("select * from {$table} where company_id={$company} order by id")->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR));
        }

        return $out;
    }
}
