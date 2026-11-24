<?php

namespace Tests\Feature\Modules\Jobs;

use App\Models\User;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Support\Scope\CompanyAccessFence;
use Closure;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

class JobCommandApiCommitTest extends TestCase
{
    use BuildsStartedSalesJob;
    use DatabaseMigrations;

    private int $jobId;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        DB::transaction(fn () => $this->bootStartedJobFixture());
        $this->jobId = DB::transaction(fn () => $this->releaseTheService()['job_id']);
        $this->operator = User::factory()->create();
        $this->grantJobDuties($this->operator, ...array_diff($this->jobDuties, ['job.receive']));
        $this->actingAs($this->operator);
    }

    private function payload(array $extra = [], ?int $generation = null): array
    {
        return $extra + ['expected_generation' => $generation ?? (int) DB::table('service_jobs')->where('id', $this->jobId)->value('generation'),
            'request_key' => 'http-'.bin2hex(random_bytes(8)), 'command_identity' => JobLifecycleIntent::newCommandIdentity()];
    }

    private function postCommand(string $path, array $payload): TestResponse
    {
        return $this->postJson('/api/v1/jobs/'.$this->jobId.'/'.$path, $payload);
    }

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    /** Real independent processes, each issuing HTTP against its own PostgreSQL connection. */
    private function race(Closure $childWork, Closure $parentWork): array
    {
        $path = sys_get_temp_dir().'/job_http_'.getmypid();
        $start = $path.'.start';
        @unlink($path);
        @unlink($start);
        $result = static function (Closure $work): array {
            try {
                $response = $work();

                return ['status' => $response->getStatusCode(), 'body' => $response->json()];
            } catch (\Throwable $e) {
                return ['status' => 0, 'error' => $e->getMessage()];
            }
        };
        $default = config('database.default');
        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                config(['database.connections.http_child' => config('database.connections.testing'), 'database.default' => 'http_child']);
                for ($i = 0; ! file_exists($start) && $i < 5000; $i++) {
                    usleep(1000);
                }
                file_put_contents($path, json_encode($result($childWork)));
                posix_kill(getmypid(), SIGKILL);
            }
            config(['database.connections.http_parent' => config('database.connections.testing'), 'database.default' => 'http_parent']);
            file_put_contents($start, 'go');
            $parent = $result($parentWork);
            config(['database.default' => $default]);
            DB::purge('http_parent');
            pcntl_waitpid($pid, $status);
            $child = json_decode(file_get_contents($path), true);
            $this->assertIsArray($child);

            return [$child, $parent];
        } finally {
            config(['database.default' => $default]);
            @unlink($path);
            @unlink($start);
        }
    }

    public function test_duplicate_committed_http_requests_write_once_and_response_observes_committed_evidence(): void
    {
        $payload = $this->payload(['assignee_id' => $this->worker->id]);
        $work = fn () => $this->postCommand('assign', $payload);
        $results = $this->race($work, $work);
        $this->assertSame([200, 200], array_column($results, 'status'), json_encode($results));
        $this->assertSame($results[0]['body']['data']['event_id'], $results[1]['body']['data']['event_id']);
        $replayed = array_column(array_column($results, 'body'), 'data');
        $this->assertEqualsCanonicalizing([false, true], array_column($replayed, 'replayed'));
        config(['database.connections.http_probe' => config('database.connections.testing')]);
        $this->assertSame(1, DB::connection('http_probe')->table('job_assignment_events')->count());
        $this->assertSame(2, DB::connection('http_probe')->table('service_jobs')->where('id', $this->jobId)->value('generation'));
        $this->assertSame(0, DB::transactionLevel());
        DB::purge('http_probe');
    }

    public function test_competing_commands_from_one_generation_have_one_winner(): void
    {
        $assign = $this->payload(['assignee_id' => $this->worker->id]);
        $hold = $this->payload(['reason' => 'Wait']);
        $results = $this->race(fn () => $this->postCommand('assign', $assign), fn () => $this->postCommand('holds', $hold));
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'status'), json_encode($results));
        $loser = array_values(array_filter($results, fn ($r) => $r['status'] === 422))[0];
        $this->assertSame('STALE_GENERATION', $loser['body']['reason_code']);
        $this->assertSame(2, DB::table('job_events')->count());
        $this->assertSame(1, DB::table('job_operation_receipts')->count());
    }

    public function test_real_completion_and_close_failures_rollback_all_evidence_before_http_response(): void
    {
        $this->postCommand('assign', $this->payload(['assignee_id' => $this->worker->id]))->assertOk();
        $this->postCommand('start', $this->payload())->assertOk();
        DB::unprepared("CREATE FUNCTION zz_http_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'HTTP atomic failure' USING ERRCODE='P0001'; END $$");
        try {
            foreach (['job_completions', 'handover_fulfilment_receipts', 'job_operation_receipts'] as $table) {
                DB::unprepared("CREATE TRIGGER zz_http_inject AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION zz_http_failure()");
                $this->postCommand('complete', $this->payload(['statement' => 'Whole delivery']))->assertStatus(500)->assertJsonMissingPath('data');
                DB::unprepared("DROP TRIGGER zz_http_inject ON {$table}");
                $this->assertSame(0, DB::table('job_completions')->count());
                $this->assertSame(0, DB::table('handover_fulfilment_receipts')->count());
                $this->assertSame(3, DB::table('job_events')->count());
                $this->assertSame('ACTIVE', DB::table('service_jobs')->value('state'));
            }
            $complete = $this->payload(['statement' => 'Whole delivery']);
            $this->postCommand('complete', $complete)->assertOk();
            $assess = $this->payload();
            unset($assess['command_identity']);
            DB::unprepared('CREATE TRIGGER zz_http_inject AFTER INSERT ON job_closeout_assessments FOR EACH ROW EXECUTE FUNCTION zz_http_failure()');
            $this->postCommand('closeout-assessments', $assess)->assertStatus(500);
            DB::unprepared('DROP TRIGGER zz_http_inject ON job_closeout_assessments');
            $this->assertSame(0, DB::table('job_closeout_assessments')->count());
            $assessment = $this->postCommand('closeout-assessments', $assess)->assertOk()->json('data.assessment_id');
            DB::unprepared('CREATE TRIGGER zz_http_inject AFTER INSERT ON job_closeouts FOR EACH ROW EXECUTE FUNCTION zz_http_failure()');
            $close = $this->payload(['assessment_id' => $assessment]);
            $this->postCommand('close', $close)->assertStatus(500);
            DB::unprepared('DROP TRIGGER zz_http_inject ON job_closeouts');
            $this->assertSame(0, DB::table('job_closeouts')->count());
            $this->assertSame(4, DB::table('job_events')->count());
            $this->postCommand('close', $close)->assertOk();
            $this->assertSame(1, DB::table('handover_fulfilment_receipts')->count());
        } finally {
            DB::unprepared('DROP FUNCTION zz_http_failure() CASCADE');
        }
    }

    /**
     * A real source wait cannot coexist with a normally committed security revocation: security writers need the company barrier already held by the reader.
     * Prove that ordering separately; for the post-source-wait recheck change authority in the waiting reader after its actual lock acquisition.
     */
    private function blockedContext(string $change, bool $lookup = false): void
    {
        $requestPath = '/api/v1/jobs/'.$this->jobId.($lookup ? '/eligible-assignees?search=Sam' : '/operation-context?operation=HOLD');
        $path = sys_get_temp_dir().'/job_context_wait_'.getmypid();
        @unlink($path);
        @unlink($path.'.pid');
        $default = config('database.default');
        DB::beginTransaction();
        if ($change === 'company') {
            app(CompanyAccessFence::class)->lock(true);
        } else {
            DB::table('sales_authority_heads')->where('company_id', 1)->lockForUpdate()->get();
        }
        $blockingPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                config(['database.connections.context_wait_child' => config('database.connections.testing'), 'database.default' => 'context_wait_child']);
                $this->operator = User::query()->findOrFail($this->operator->id);
                $this->actingAs($this->operator);
                file_put_contents($path.'.pid', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
                $queries = [];
                $changed = false;
                DB::listen(function ($query) use (&$queries, &$changed, $change, $lookup): void {
                    $queries[] = $query->sql;
                    if ($change === 'post-wait' && ! $changed && str_contains($query->sql, 'customer_acceptances') && str_contains($query->sql, 'for update')) {
                        $changed = true;
                        $this->operator->revokePermissionTo($lookup ? 'job.assign' : 'job.hold');
                        app(PermissionRegistrar::class)->forgetCachedPermissions();
                    }
                });
                try {
                    $response = $this->getJson($requestPath);
                    file_put_contents($path, json_encode(['status' => $response->getStatusCode(), 'body' => $response->json(), 'queries' => $queries, 'changed' => $changed]));
                } catch (\Throwable $e) {
                    file_put_contents($path, json_encode(['error' => $e->getMessage()]));
                }
                posix_kill(getmypid(), SIGKILL);
            }
            $waited = false;
            for ($i = 0; $i < 70; $i++) {
                if (file_exists($path.'.pid')) {
                    $childPid = (int) file_get_contents($path.'.pid');
                    $row = DB::selectOne('SELECT ?::int = ANY(pg_blocking_pids(?::int)) as waiting', [$blockingPid, $childPid]);
                    if ($row->waiting) {
                        $waited = true;
                        break;
                    }
                }
                usleep(5000);
            }
            $this->assertTrue($waited, 'child must actually block on the real PostgreSQL fence');
            if ($change === 'company') {
                DB::table('users')->where('id', $this->operator->id)->update(['status' => 'SUSPENDED']);
            }
            if ($change === 'source') {
                DB::statement('SET LOCAL session_replication_role = replica');
                DB::table('job_baseline_components')->where('job_id', $this->jobId)->update(['issued_line_id' => 99999999]);
                DB::statement('SET LOCAL session_replication_role = DEFAULT');
            }
            DB::commit();
            pcntl_waitpid($pid, $status);
            $result = json_decode(file_get_contents($path), true);
            $this->assertSame($change === 'source' ? 404 : 403, $result['status'] ?? null, json_encode($result));
            $this->assertArrayNotHasKey('data', $result['body']);
            $this->assertSame([], array_values(array_filter($result['queries'], fn ($sql) => str_contains($sql, 'ilike'))), 'no employee search before proof');
            $this->assertSame([], array_values(array_filter($result['queries'], fn ($sql) => str_contains($sql, 'jsonb_agg'))), 'no profile materialised');
            $this->assertSame([], array_values(array_filter($result['queries'], fn ($sql) => str_contains($sql, 'service_jobs') && str_contains($sql, 'for update'))));
            if ($change === 'post-wait') {
                $this->assertTrue($result['changed']);
            }
            // The controlled post-wait revocation shares the refused read transaction and rolls back with it. The company/source writers above commit independently.
            $this->getJson($requestPath)->assertStatus(match ($change) {
                'source' => 404,'company' => 403,default => 200
            });
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if (isset($pid) && $pid > 0) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            config(['database.default' => $default]);
            @unlink($path);
            @unlink($path.'.pid');
        }
    }

    public function test_company_barrier_serialises_a_real_revocation_and_the_next_request_refuses(): void
    {
        $this->blockedContext('company');
    }

    public function test_source_record_revocation_during_a_real_source_fence_wait_discloses_nothing(): void
    {
        $this->blockedContext('source');
    }

    public function test_authority_is_rechecked_after_an_actual_source_wait_before_any_profile_is_materialised(): void
    {
        $this->blockedContext('post-wait');
    }

    public function test_lookup_discloses_no_employees_after_source_revocation_during_a_real_wait(): void
    {
        $this->blockedContext('source', true);
    }

    public function test_lookup_rechecks_assignment_authority_after_a_real_source_wait(): void
    {
        $this->blockedContext('post-wait', true);
    }
}
