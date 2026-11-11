<?php

namespace Tests\Feature\Modules\Sales;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HandoverStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_history_table_refuses_truncate_even_when_empty(): void
    {
        foreach (['handover_plans', 'handover_allocations', 'handover_stages', 'handover_baselines', 'handover_readiness_evidence'] as $table) {
            try {
                DB::transaction(fn () => DB::statement("TRUNCATE {$table} CASCADE"));
                $this->fail('Preparation evidence must refuse truncate.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
            $this->assertSame(2, (int) DB::selectOne("select count(*) as n from pg_trigger where tgrelid=?::regclass and not tgisinternal and (tgname like '%append_only' or tgname like '%truncate')", [$table])->n);
        }
    }
}
