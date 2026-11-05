<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** ADR 0157: one additive, atomic access ledger/share contract; existing ownership evidence is retained verbatim. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared($this->sql('up.sql'));
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE company_access_generations, quotation_families, quotation_ownership_events, quotation_access_events, quotation_access_grants, quotation_access_revocations IN ACCESS EXCLUSIVE MODE');
        foreach (['quotation_access_events', 'quotation_access_grants', 'quotation_access_revocations'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Quotation access evidence exists; rolling back the ledger would erase its counter chain. A separately approved backout is required.');
            }
        }
        DB::unprepared($this->sql('down.sql'));
    }

    private function sql(string $name): string
    {
        $sql = file_get_contents(database_path('sql/quotation-access-ledger/'.$name));
        if ($sql === false) {
            throw new RuntimeException('The quotation access ledger migration SQL is missing.');
        }

        return $sql;
    }
};
