<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['approval_policies', 'approval_policy_conditions', 'approval_policy_steps', 'approval_subject_settings', 'approval_authorities', 'approval_delegations'];

    public function up(): void
    {
        // Statement barriers run before row locks. Conservatively fence all engine configuration:
        // a statement can include several subject types and must never discover Sales after a row lock.
        foreach (self::TABLES as $table) {
            DB::statement("CREATE TRIGGER sales_approval_configuration_barrier BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION sales_authority_writer_barrier()");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement("DROP TRIGGER sales_approval_configuration_barrier ON {$table}");
        }
    }
};
