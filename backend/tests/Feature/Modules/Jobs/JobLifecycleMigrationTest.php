<?php

namespace Tests\Feature\Modules\Jobs;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Modules\Jobs\Concerns\BuildsLifecycleChains;
use Tests\TestCase;

/**
 * J1-F1 — installing and removing the lifecycle foundation over a database that may already hold SP0b evidence: empty and populated, up and down. Down and up are
 * run inside the test transaction (PostgreSQL DDL is transactional), so every case is undone at the end; migrate-cycle.sh proves the same on a fresh database.
 */
class JobLifecycleMigrationTest extends TestCase
{
    use BuildsLifecycleChains;
    use RefreshDatabase;

    private const NEW_TABLES = ['job_assignment_events', 'job_holds', 'job_hold_resolutions', 'job_operation_receipts'];

    private User $user;

    private User $worker;

    private int $otherUser = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->worker = User::factory()->create();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_26_090000_create_job_lifecycle_foundation.php');
    }

    private function lifecycleFunctions(): array
    {
        return collect(DB::select("select proname from pg_proc where proname like 'job_lifecycle_%' or proname = 'job_evidence_append_only_guard' order by 1"))->pluck('proname')->all();
    }

    private function assertInstalled(): void
    {
        foreach (self::NEW_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumns('job_events', ['command_identity', 'semantic_intent_hash']));
        $this->assertSame(['job_evidence_append_only_guard', 'job_lifecycle_consistency_v1', 'job_lifecycle_evidence_guard_v1', 'job_lifecycle_head_guard_v1'], $this->lifecycleFunctions());
    }

    private function assertUninstalled(): void
    {
        foreach (self::NEW_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertFalse(Schema::hasColumn('job_events', 'command_identity'));
        $this->assertFalse(Schema::hasColumn('job_events', 'semantic_intent_hash'));
        $this->assertSame(['job_evidence_append_only_guard'], $this->lifecycleFunctions(), 'only the SP0b guard remains');
        $this->assertSame(0, (int) DB::selectOne("select count(*) c from pg_constraint where conname in ('job_events_job_unique','job_events_command_unique','job_events_identity_shape')")->c);
    }

    private function assertSp0bGuardsIntact(): void
    {
        $guards = DB::select("select c.relname as tbl, (t.tgtype & 32) <> 0 as is_truncate from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid
            where not t.tgisinternal and p.proname = 'job_evidence_append_only_guard' and c.relname in ('job_handover_origins','job_baselines','job_events')");
        $this->assertCount(6, $guards, 'row and truncate guards on the three SP0b evidence tables');
    }

    public function test_an_empty_store_rolls_back_and_applies_again(): void
    {
        $migration = $this->migration();
        $this->assertInstalled();

        $migration->down();
        $this->assertUninstalled();
        $this->assertSp0bGuardsIntact();
        foreach (['service_jobs', 'job_handover_origins', 'job_baselines', 'job_events'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (SP0b) survives the rollback");
        }

        $migration->up();
        $this->assertInstalled();
        $this->assertSp0bGuardsIntact();
    }

    public function test_any_new_row_or_lifecycle_identity_makes_down_refuse_before_dropping_anything(): void
    {
        $migration = $this->migration();
        $job = $this->newJob();
        $this->assign($job, $this->worker->id);
        $this->placeHold($job);

        try {
            $migration->down();
            $this->fail('Rolling back with lifecycle evidence present must refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Plan a backout first', $e->getMessage());
        }
        $this->assertInstalled();
        $this->assertSame(1, DB::table('job_assignment_events')->count());
        $this->assertSame(1, DB::table('job_holds')->count());
        $this->assertSame(1, DB::table('job_operation_receipts')->where('operation', 'ASSIGN')->count());
    }

    public function test_a_lifecycle_command_identity_alone_makes_down_refuse(): void
    {
        $migration = $this->migration();
        $job = $this->newJob();
        // A RELEASE event may carry the identity pair; with no child rows at all, that identity alone is lifecycle evidence.
        DB::table('job_events')->where('job_id', $job)->limit(1)->get(); // evidence is append-only, so insert a second Job instead of updating
        $other = DB::table('service_jobs')->insertGetId(['company_id' => 1, 'code' => 'IDENT', 'state' => 'RELEASED', 'generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('job_events')->insert(['company_id' => 1, 'job_id' => $other, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'RELEASED', 'prior_generation' => 0, 'next_generation' => 1,
            'occurrence_precision' => 'EXACT', 'recorded_at' => $this->at(0), 'actor_id' => $this->user->id, 'evidence_version' => 1,
            'command_identity' => '6f1d2c3e-1111-4222-8333-444455556666', 'semantic_intent_hash' => str_repeat('a', 64)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lifecycle command identity');
        try {
            $migration->down();
        } finally {
            $this->assertInstalled();
        }
    }

    public function test_valid_populated_sp0b_evidence_installs_unchanged(): void
    {
        $migration = $this->migration();
        $migration->down();
        $job = $this->newJob('KEEP-1');
        $second = $this->newJob('KEEP-2');
        DB::table('job_baselines')->insert(['company_id' => 1, 'job_id' => $job, 'version' => 1, 'fingerprint' => 'fp', 'scope_description' => 'Inspect', 'completion_rule_version' => 'c1', 'recorded_at' => $this->at(0)]);
        $before = [
            'jobs' => DB::table('service_jobs')->orderBy('id')->get()->toArray(),
            'events' => DB::table('job_events')->orderBy('id')->get(['id', 'company_id', 'job_id', 'sequence', 'operation', 'to_state', 'next_generation'])->toArray(),
            'baselines' => DB::table('job_baselines')->orderBy('id')->get()->toArray(),
        ];

        $migration->up();
        $this->assertInstalled();
        $this->assertSp0bGuardsIntact();
        $this->assertEquals($before['jobs'], DB::table('service_jobs')->orderBy('id')->get()->toArray());
        $this->assertEquals($before['events'], DB::table('job_events')->orderBy('id')->get(['id', 'company_id', 'job_id', 'sequence', 'operation', 'to_state', 'next_generation'])->toArray());
        $this->assertEquals($before['baselines'], DB::table('job_baselines')->orderBy('id')->get()->toArray());
        $this->assertSame(0, DB::table('job_events')->whereNotNull('command_identity')->count(), 'no identity is invented for legacy events');
        $this->assertSame(2, DB::table('service_jobs')->whereIn('id', [$job, $second])->count());
        // Those Jobs can carry the new evidence straight away.
        DB::transaction(function () use ($job): void {
            $this->assign($job, $this->worker->id);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->assertSame(1, DB::table('job_assignment_events')->count());
    }

    public function test_malformed_history_aborts_the_install_and_leaves_no_residue(): void
    {
        $migration = $this->migration();
        $migration->down();
        $head = fn (string $code, string $state = 'RELEASED', int $generation = 1) => DB::table('service_jobs')->insertGetId(['company_id' => 1, 'code' => $code, 'state' => $state, 'generation' => $generation, 'created_at' => now(), 'updated_at' => now()]);
        $event = fn (int $job, array $over = []) => DB::table('job_events')->insert(['company_id' => 1, 'job_id' => $job, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'RELEASED',
            'prior_generation' => 0, 'next_generation' => 1, 'occurrence_precision' => 'EXACT', 'recorded_at' => $this->at(0), 'actor_id' => $this->user->id, 'evidence_version' => 1, ...$over]);

        $cases = [
            'a job with no event' => fn () => $head('NOEVENT'),
            'a head that differs from its latest event' => function () use ($head, $event): void {
                $event($head('STALE', 'ACTIVE', 2));
            },
            'a first event that is not a release' => function () use ($head, $event): void {
                $event($head('BADFIRST'), ['operation' => 'RELEASE', 'prior_generation' => 1, 'next_generation' => 2]);
            },
            'a history that does not start at 1' => function () use ($head, $event): void {
                $event($head('GAP'), ['sequence' => 2]);
            },
            'a repeated release (independent review finding)' => function () use ($head, $event): void {
                $job = $head('REPEATED', 'RELEASED', 2);
                $event($job);
                $event($job, ['sequence' => 2, 'operation' => 'RELEASE', 'from_state' => 'RELEASED', 'to_state' => 'RELEASED', 'prior_generation' => 1, 'next_generation' => 2]);
            },
            'an event outside release-only history' => function () use ($head, $event): void {
                $job = $head('ODD');
                $event($job);
                $event($job, ['sequence' => 2, 'operation' => 'START', 'from_state' => 'RELEASED', 'to_state' => 'ACTIVE', 'prior_generation' => 1, 'next_generation' => 2]);
            },
        ];
        foreach ($cases as $label => $seed) {
            DB::beginTransaction();
            try {
                $seed();
                try {
                    $migration->up();
                    $this->fail("Installing over {$label} must abort.");
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('not compatible with the J1-F1 lifecycle foundation', $e->getMessage(), $label);
                }
                $this->assertUninstalled();
                $this->assertSp0bGuardsIntact();
            } finally {
                DB::rollBack();
            }
        }
        // The rejected rows are gone with the savepoints; the clean store installs.
        $migration->up();
        $this->assertInstalled();
    }
}
