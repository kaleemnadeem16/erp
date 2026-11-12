<?php

namespace Tests\Feature\Modules\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 5 Batch 1 — installing and removing the Jobs receiver foundation: an empty store rolls back and applies again (the populated refusal is proven in ReleasedServiceReceiverTest, which has the release fixture). Run inside the test transaction (DDL is transactional); migrate-cycle.sh proves the same on a fresh database.
 */
class JobReceiverMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['job_sales_origins', 'job_baseline_components', 'job_activities'];

    private const FUNCTIONS = ['job_activity_guard_v1', 'job_baseline_component_guard_v1', 'job_sales_origin_consistency_v1', 'job_sales_origin_guard_v1'];

    private function migration(): object
    {
        return require base_path('database/migrations/2026_12_13_200000_create_job_receiver_foundation.php');
    }

    private function functions(): array
    {
        return collect(DB::select("select proname from pg_proc where proname in ('".implode("','", self::FUNCTIONS)."') order by 1"))->pluck('proname')->all();
    }

    public function test_an_empty_store_rolls_back_and_applies_again_leaving_every_other_job_object_alone(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertSame(self::FUNCTIONS, $this->functions());
        $migration = $this->migration();

        $migration->down();
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertSame([], $this->functions());
        foreach (['service_jobs', 'job_handover_origins', 'job_baselines', 'job_events', 'handover_release_events', 'service_completion_bases'] as $kept) {
            $this->assertTrue(Schema::hasTable($kept), "{$kept} is untouched by the backout");
        }

        $migration->up();
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertSame(self::FUNCTIONS, $this->functions());
        $triggers = DB::select("select c.relname as tbl, p.proname as fn from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid
            where not t.tgisinternal and c.relname in ('job_sales_origins','job_baseline_components','job_activities') and p.proname = 'job_evidence_append_only_guard'");
        $this->assertCount(6, $triggers, 'a row guard and a truncate guard on each receiver table');
    }
}
