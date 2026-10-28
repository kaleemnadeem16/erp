<?php

namespace Tests\Feature\Modules\Jobs;

use App\Models\User;
use App\Modules\Jobs\Models\JobBaseline;
use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Models\JobHandoverOrigin;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\JobsException;
use App\Modules\Jobs\Support\ReleasedServiceInput;
use App\Modules\Jobs\Support\ReleasedServiceValidator;
use App\Support\PermissionRegistry;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * SP0b — the disabled Jobs foundation over the real database: four company-scoped tables, composite keys, the stated checks and uniqueness, append-only
 * evidence with its own guard, a refusing rollback, and no way in. Rows here are test fixtures; nothing in the application creates a Job. Only the stated
 * constraints are tested — no state-machine claim is made or checked.
 */
class JobsFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const HASH = 'a3f1c0d2e4b6a8c0d2e4f6a8b0c2d4e6f8a0b2c4d6e8f0a2b4c6d8e0f2a4b6c8';

    private User $user;

    private int $otherCompany;

    private int $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherUser = DB::table('users')->insertGetId(['company_id' => $this->otherCompany, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function job(string $code = 'JOB-1', int $company = 1): int
    {
        return DB::table('service_jobs')->insertGetId(['company_id' => $company, 'code' => $code, 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $over */
    private function origin(int $jobId, array $over = []): array
    {
        return ['company_id' => 1, 'job_id' => $jobId, 'source_contract_version' => 'r7.v1', 'target_purpose' => 'SERVICE', 'business_identity' => 'stage-1', 'intent_hash' => self::HASH, 'recorded_at' => now(), ...$over];
    }

    /** @param array<string, mixed> $over */
    private function baseline(int $jobId, array $over = []): array
    {
        return ['company_id' => 1, 'job_id' => $jobId, 'version' => 1, 'fingerprint' => 'fp-1', 'scope_description' => 'Inspect the plant', 'completion_rule_version' => 'c1', 'recorded_at' => now(), ...$over];
    }

    /** @param array<string, mixed> $over */
    private function event(int $jobId, array $over = []): array
    {
        return ['company_id' => 1, 'job_id' => $jobId, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'RELEASED', 'prior_generation' => 0, 'next_generation' => 1,
            'occurrence_precision' => 'EXACT', 'recorded_at' => now(), 'actor_id' => $this->user->id, 'evidence_version' => 1, ...$over];
    }

    private function assertDbRefuses(Closure $write, string $contains): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());

            return;
        }
        $this->fail("The database accepted a write that should have been refused ({$contains}).");
    }

    public function test_the_four_tables_exist_and_the_queue_table_is_untouched(): void
    {
        foreach (['service_jobs', 'job_handover_origins', 'job_baselines', 'job_events'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumn($table, 'company_id'), "{$table} is company-scoped");
        }
        foreach (['origin_id', 'current_baseline_id', 'last_event_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('service_jobs', $column), "service_jobs has no {$column}");
        }
        foreach (['quantity', 'measure_id', 'amount', 'currency_id', 'stage_id', 'acceptance_id', 'allocation_id'] as $column) {
            foreach (['service_jobs', 'job_handover_origins', 'job_baselines', 'job_events'] as $table) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$table} must not carry {$column} yet");
            }
        }
        $this->assertTrue(Schema::hasColumn('jobs', 'queue'), 'the Laravel queue table is not the domain Job');
        $this->assertFalse(Schema::hasColumn('jobs', 'company_id'));
    }

    public function test_a_job_with_its_origin_baselines_and_events_can_be_recorded_as_fixtures(): void
    {
        $job = ServiceJob::create(['code' => 'JOB-1', 'state' => 'RELEASED', 'generation' => 1]);
        $origin = JobHandoverOrigin::create(['job_id' => $job->id, 'source_contract_version' => 'r7.v1', 'target_purpose' => 'SERVICE', 'business_identity' => 'stage-1', 'intent_hash' => self::HASH, 'recorded_at' => now()]);
        $first = JobBaseline::create(['job_id' => $job->id, 'version' => 1, 'fingerprint' => 'fp-1', 'scope_description' => 'Inspect', 'completion_rule_version' => 'c1', 'recorded_at' => now()]);
        $second = JobBaseline::create(['job_id' => $job->id, 'version' => 2, 'predecessor_id' => $first->id, 'fingerprint' => 'fp-2', 'scope_description' => 'Inspect twice', 'completion_rule_version' => 'c1', 'recorded_at' => now()]);
        $event = JobEvent::create($this->event($job->id) + []);

        $this->assertSame($job->id, $origin->job_id);
        $this->assertSame($first->id, $second->predecessor_id);
        $this->assertSame(1, $event->next_generation);
        $this->assertSame(1, (int) DB::table('job_events')->where('job_id', $job->id)->count());
    }

    public function test_the_database_refuses_cross_company_references(): void
    {
        $mine = $this->job();
        $theirs = $this->job('JOB-1', $this->otherCompany);

        $this->assertDbRefuses(fn () => DB::table('job_handover_origins')->insert($this->origin($mine, ['company_id' => $this->otherCompany])), 'job_handover_origins_job_fk');
        $this->assertDbRefuses(fn () => DB::table('job_baselines')->insert($this->baseline($mine, ['company_id' => $this->otherCompany])), 'job_baselines_job_fk');
        $this->assertDbRefuses(fn () => DB::table('job_events')->insert($this->event($mine, ['company_id' => $this->otherCompany, 'actor_id' => $this->otherUser])), 'job_events_job_fk');
        $this->assertDbRefuses(fn () => DB::table('job_events')->insert($this->event($mine, ['actor_id' => $this->otherUser])), 'job_events_actor_fk');
        $this->assertDbRefuses(fn () => DB::table('job_events')->insert($this->event($mine, ['operation' => ' '])), 'is not allowed by the lifecycle foundation');
        $this->assertDbRefuses(fn () => DB::table('job_events')->insert($this->event($theirs)), 'job_events_job_fk');

        // A predecessor must be a baseline of the SAME job in the same company.
        $base = DB::table('job_baselines')->insertGetId($this->baseline($mine));
        $this->assertDbRefuses(fn () => DB::table('job_baselines')->insert($this->baseline($theirs, ['company_id' => $this->otherCompany, 'version' => 2, 'predecessor_id' => $base])), 'job_baselines_predecessor_fk');
        $sibling = $this->job('JOB-2');
        $this->assertDbRefuses(fn () => DB::table('job_baselines')->insert($this->baseline($sibling, ['version' => 2, 'predecessor_id' => $base])), 'job_baselines_predecessor_fk');
    }

    public function test_the_database_refuses_duplicate_anchors(): void
    {
        $a = $this->job('JOB-A');
        $b = $this->job('JOB-B');
        DB::table('job_handover_origins')->insert($this->origin($a));
        DB::table('job_baselines')->insert($this->baseline($a));
        DB::table('job_events')->insert($this->event($a));

        $this->assertDbRefuses(fn () => $this->job('JOB-A'), 'service_jobs_code_unique');
        $this->assertDbRefuses(fn () => DB::table('job_handover_origins')->insert($this->origin($a, ['business_identity' => 'other'])), 'job_handover_origins_job_unique');
        $this->assertDbRefuses(fn () => DB::table('job_handover_origins')->insert($this->origin($b)), 'job_handover_origins_identity_unique');
        $this->assertDbRefuses(fn () => DB::table('job_baselines')->insert($this->baseline($a, ['fingerprint' => 'again'])), 'job_baselines_version_unique');
        $this->assertDbRefuses(fn () => DB::table('job_events')->insert($this->event($a, ['next_generation' => 2])), 'job_events_sequence_unique');
        $this->assertDbRefuses(fn () => DB::table('job_events')->insert($this->event($a, ['sequence' => 2])), 'job_events_generation_unique');

        // The same business identity is fine for another purpose, and the same code in another company.
        DB::table('job_handover_origins')->insert($this->origin($b, ['target_purpose' => 'WARRANTY']));
        $this->job('JOB-A', $this->otherCompany);
        $this->assertSame(2, DB::table('job_handover_origins')->count());
    }

    public function test_the_database_refuses_invalid_raw_rows(): void
    {
        $job = $this->job();
        $jobRow = ['company_id' => 1, 'code' => 'X', 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()];
        foreach ([['state' => 'DONE'], ['state' => 'released'], ['generation' => 0], ['code' => ' ']] as $bad) {
            $this->assertDbRefuses(fn () => DB::table('service_jobs')->insert([...$jobRow, ...$bad]), 'service_jobs_shape');
        }
        foreach ([['intent_hash' => 'NOT-HEX'], ['intent_hash' => strtoupper(self::HASH)], ['target_purpose' => ''], ['business_identity' => ' '], ['source_contract_version' => '']] as $bad) {
            $this->assertDbRefuses(fn () => DB::table('job_handover_origins')->insert($this->origin($job, $bad)), 'job_handover_origins_shape');
        }
        foreach ([['version' => 0], ['version' => 1, 'predecessor_id' => 1], ['scope_description' => ' '], ['completion_rule_version' => ''], ['fingerprint' => '']] as $bad) {
            $this->assertDbRefuses(fn () => DB::table('job_baselines')->insert($this->baseline($job, $bad)), 'job_baselines_shape');
        }
        foreach ([['next_generation' => 0], ['sequence' => 0], ['to_state' => 'DONE'], ['from_state' => 'NOPE'], ['evidence_version' => 0], ['reason' => ' ']] as $bad) {
            $this->assertDbRefuses(fn () => DB::table('job_events')->insert($this->event($job, $bad)), 'job_events_shape');
        }
    }

    public function test_evidence_is_append_only_through_the_jobs_own_guard(): void
    {
        $job = $this->job();
        DB::table('job_handover_origins')->insert($this->origin($job));
        DB::table('job_baselines')->insert($this->baseline($job));
        DB::table('job_events')->insert($this->event($job));
        // The fixtures are a coherent chain (head RELEASED at generation 1, one RELEASE event); settle the commit-time checks, which TRUNCATE will not run past.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        foreach (['job_handover_origins' => ['target_purpose' => 'X'], 'job_baselines' => ['fingerprint' => 'x'], 'job_events' => ['reason' => 'x']] as $table => $change) {
            $this->assertDbRefuses(fn () => DB::table($table)->update($change), 'append-only');
            $this->assertDbRefuses(fn () => DB::table($table)->delete(), 'append-only');
            // job_events is now referenced by the lifecycle tables, so PostgreSQL refuses a lone TRUNCATE on it by itself; truncated together with them, only the guard stands in the way.
            $set = $table === 'job_events' ? 'job_events, job_assignment_events, job_holds, job_hold_resolutions, job_operation_receipts' : $table;
            $this->assertDbRefuses(fn () => DB::statement("TRUNCATE TABLE {$set}"), 'append-only');
        }
        // Truncating the parent with CASCADE would empty the evidence tables too; their own guards refuse that as well.
        $this->assertDbRefuses(fn () => DB::statement('TRUNCATE TABLE service_jobs CASCADE'), 'append-only');
        // The parent cannot be deleted from under its evidence either.
        $this->assertDbRefuses(fn () => DB::table('service_jobs')->where('id', $job)->delete(), 'violates foreign key');

        $guards = DB::select("select c.relname as tbl, p.proname as fn from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid
            where not t.tgisinternal and c.relname in ('job_handover_origins','job_baselines','job_events') and p.proname not like 'job_lifecycle_%' order by c.relname");
        $this->assertCount(6, $guards, 'a row guard and a truncate guard on each evidence table');
        foreach ($guards as $g) {
            $this->assertSame('job_evidence_append_only_guard', $g->fn, "{$g->tbl} uses the Jobs guard, never the Documents one");
        }
        // The head is not history (its state and generation advance with each event), so it carries no append-only guard; J1-F1 adds only its own head guard and commit-time check.
        $this->assertSame(['service_jobs_lifecycle_consistency', 'service_jobs_lifecycle_head_guard'], collect(DB::select("select t.tgname from pg_trigger t join pg_class c on c.oid = t.tgrelid where not t.tgisinternal and c.relname = 'service_jobs' order by 1"))->pluck('tgname')->all());
    }

    public function test_a_populated_rollback_refuses_and_changes_nothing(): void
    {
        $migration = require base_path('database/migrations/2026_10_22_090000_create_jobs_foundation.php');

        $job = $this->job();
        try {
            $migration->down();
            $this->fail('Rolling back with a Job row present must refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Plan a backout first', $e->getMessage());
        }
        DB::table('job_events')->insert($this->event($job));
        try {
            $migration->down();
            $this->fail('Rolling back with evidence present must refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('job_events has rows', $e->getMessage());
        }
        foreach (['service_jobs', 'job_handover_origins', 'job_baselines', 'job_events'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} survived the refused rollback");
        }
        $this->assertSame(1, DB::table('job_events')->count());
    }

    public function test_there_is_no_way_in(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertSame([], $uris->filter(fn ($u) => preg_match('#^api/v1/(service-)?jobs#', $u) === 1)->values()->all());
        $this->assertSame([], array_values(array_filter(app(PermissionRegistry::class)->all(), fn ($p) => preg_match('/^jobs?[.:_]/', $p) === 1)));

        $files = glob(base_path('app/Modules/Jobs/*.php')) ?: [];
        foreach (['Models', 'Support'] as $dir) {
            $files = [...$files, ...(glob(base_path("app/Modules/Jobs/{$dir}/*.php")) ?: [])];
        }
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            if (basename($file) === 'JobAccess.php') {
                // J-AU0's reviewed capability predicate necessarily calls ->can(); it must still register, route and gate nothing (JobAccessFoundationTest covers its behaviour).
                $this->assertDoesNotMatchRegularExpression('/authorize\(|Gate::|Route::|registerVocabulary|Receive[A-Z]\w*|["\']jobs?\.\w+["\']/', $code, 'JobAccess must not gate, route or register anything');

                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/->can\(|authorize\(|Gate::|Route::|registerVocabulary|Receive[A-Z]\w*|["\']jobs?\.\w+["\']/', $code, basename($file).' must not guard, route or register anything');
        }
        $this->assertDirectoryDoesNotExist(base_path('app/Modules/Jobs/Actions'));
        $this->assertFileDoesNotExist(base_path('app/Modules/Jobs/routes.php'));
    }

    public function test_the_released_service_value_is_only_shape_checked(): void
    {
        $validator = new ReleasedServiceValidator;
        $ok = new ReleasedServiceInput('JOB-1', 'r7.v1', 'SERVICE', 'stage-1', self::HASH, 'fp', 'Inspect the plant', 'c1');
        $validator->validate($ok);
        $this->assertSame(0, ServiceJob::count(), 'validating writes nothing');

        foreach ([
            ['job_code', new ReleasedServiceInput(' ', 'r7.v1', 'SERVICE', 'stage-1', self::HASH, 'fp', 'x', 'c1')],
            ['job_code', new ReleasedServiceInput(str_repeat('J', 41), 'r7.v1', 'SERVICE', 'stage-1', self::HASH, 'fp', 'x', 'c1')],
            ['business_identity', new ReleasedServiceInput('J', 'r7.v1', 'SERVICE', '', self::HASH, 'fp', 'x', 'c1')],
            ['intent_hash', new ReleasedServiceInput('J', 'r7.v1', 'SERVICE', 'stage-1', 'abc', 'fp', 'x', 'c1')],
            ['scope_description', new ReleasedServiceInput('J', 'r7.v1', 'SERVICE', 'stage-1', self::HASH, 'fp', ' ', 'c1')],
            ['completion_rule_version', new ReleasedServiceInput('J', 'r7.v1', 'SERVICE', 'stage-1', self::HASH, 'fp', 'x', '')],
        ] as [$field, $input]) {
            try {
                $validator->validate($input);
                $this->fail("Expected a refusal for {$field}.");
            } catch (JobsException $e) {
                $this->assertSame($field, $e->field);
            }
        }
        foreach ((new \ReflectionClass(ReleasedServiceInput::class))->getProperties() as $p) {
            $this->assertDoesNotMatchRegularExpression('/quantity|measure|amount|stage_?id|acceptance|allocation/i', $p->getName());
        }
    }
}
