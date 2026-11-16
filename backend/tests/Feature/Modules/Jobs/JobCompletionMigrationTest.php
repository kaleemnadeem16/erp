<?php

namespace Tests\Feature\Modules\Jobs;

use App\Modules\Jobs\Actions\CompleteJob;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\Jobs\BuildsStartedSalesJob;
use Tests\TestCase;

/**
 * Phase 5 — installing and removing the completion and closeout foundation: the patched lifecycle functions are restored exactly, an empty store rolls back and applies again,
 * and a populated one refuses before dropping anything.
 */
class JobCompletionMigrationTest extends TestCase
{
    use BuildsStartedSalesJob;
    use RefreshDatabase;

    private const TABLES = ['job_completions', 'job_closeout_assessments', 'job_closeouts'];

    private function migration(): object
    {
        return require base_path('database/migrations/2026_12_14_100000_create_job_completion_closeout.php');
    }

    /** The Sales fulfilment receipts depend on job_completions, so they are backed out first and re-applied after. */
    private function fulfilment(): object
    {
        return require base_path('database/migrations/2026_12_14_200000_create_service_fulfilment_receipts.php');
    }

    private function definition(string $function): string
    {
        return (string) DB::selectOne("select pg_get_functiondef('{$function}'::regprocedure) as def")->def;
    }

    public function test_an_empty_store_rolls_back_restoring_the_lifecycle_functions_and_applies_again(): void
    {
        $guard = $this->definition('job_lifecycle_evidence_guard_v1()');
        $consistency = $this->definition('job_lifecycle_consistency_v1()');
        $this->assertStringContainsString("'COMPLETE','CLOSE'", $guard);
        $this->assertStringContainsString('COMPLETE must move ACTIVE to COMPLETED', $consistency);
        $migration = $this->migration();

        $this->fulfilment()->down();
        $migration->down();
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertStringNotContainsString("'COMPLETE'", $this->definition('job_lifecycle_evidence_guard_v1()'));
        $this->assertStringNotContainsString('COMPLETE must move', $this->definition('job_lifecycle_consistency_v1()'));
        $this->assertSame(0, (int) DB::selectOne("select count(*) as n from pg_proc where proname in ('job_completion_consistency_v1','job_completion_evidence_guard_v1')")->n);
        foreach (['service_jobs', 'job_events', 'job_holds', 'job_sales_origins', 'job_baseline_components'] as $kept) {
            $this->assertTrue(Schema::hasTable($kept), "{$kept} is untouched by the backout");
        }

        $migration->up();
        $this->fulfilment()->up();
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertSame($guard, $this->definition('job_lifecycle_evidence_guard_v1()'), 'the guard is exactly as installed');
        $this->assertSame($consistency, $this->definition('job_lifecycle_consistency_v1()'), 'the consistency function is exactly as installed');
        $guards = DB::select("select c.relname from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid
            where not t.tgisinternal and c.relname in ('job_completions','job_closeout_assessments','job_closeouts') and p.proname = 'job_evidence_append_only_guard'");
        $this->assertCount(6, $guards, 'a row guard and a truncate guard on each table');
    }

    public function test_a_populated_store_refuses_backout_before_dropping_anything(): void
    {
        $this->bootStartedJobFixture();
        $job = $this->startedJob();
        app(CompleteJob::class)->execute($this->checker, $job->id, (int) $job->fresh()->generation, 'c1', JobLifecycleIntent::newCommandIdentity(), 'Done');
        $this->settleConstraints();
        $guard = $this->definition('job_lifecycle_evidence_guard_v1()');
        try {
            DB::transaction(fn () => $this->migration()->down());
            $this->fail('A populated store must refuse backout.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('erase Job completion or closeout evidence', $e->getMessage());
        }
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} survived the refused rollback");
        }
        $this->assertSame($guard, $this->definition('job_lifecycle_evidence_guard_v1()'));
        $this->assertSame(1, DB::table('job_completions')->count());
    }

    public function test_an_unexpected_function_definition_stops_the_migration_before_a_thing_changes(): void
    {
        // Run against a function body that is not the one this migration extends: it must refuse, never overwrite.
        $this->fulfilment()->down();
        $this->migration()->down();
        DB::unprepared('CREATE OR REPLACE FUNCTION job_lifecycle_evidence_guard_v1() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$');
        try {
            $this->migration()->up();
            $this->fail('A different definition must stop the migration.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nothing was changed', $e->getMessage());
        }
        $this->assertFalse(Schema::hasTable('job_completions'));
    }
}
