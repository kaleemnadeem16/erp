<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

trait AssertsPopulatedRollbackRefusal
{
    /** @param list<string> $tables */
    protected function assertPopulatedRollbackRefused(string $filename, array $tables): void
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
            $this->assertGreaterThan(0, $counts[$table], "{$filename}: proof needs populated {$table}");
        }
        $triggers = fn (): array => DB::table('pg_trigger')->where('tgisinternal', false)->orderBy('oid')->pluck('tgname')->all();
        $before = $triggers();
        $migration = require database_path('migrations/'.$filename);
        $refusal = null;
        try {
            DB::transaction(fn () => $migration->down());
        } catch (RuntimeException $exception) {
            $refusal = $exception;
        }
        $this->assertNotNull($refusal, "{$filename} erased populated evidence instead of refusing rollback.");
        $this->assertSame(RuntimeException::class, $refusal::class, 'a SQL failure is not an intentional populated rollback refusal');
        $this->assertNotSame('', $refusal->getMessage());
        $this->assertSame($before, $triggers(), 'a refused rollback retains every trigger');
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), 'a refused rollback retains every evidence row');
        }
    }
}
