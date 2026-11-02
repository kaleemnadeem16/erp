<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access_scope_limits DROP CONSTRAINT access_scope_limits_scope_check');
        DB::statement("ALTER TABLE access_scope_limits ADD CONSTRAINT access_scope_limits_scope_check CHECK (scope IN ('MY_UNITS', 'OWN', 'OWN_OR_DELEGATED'))");
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE access_scope_limits IN ACCESS EXCLUSIVE MODE');
        if (DB::table('access_scope_limits')->whereIn('scope', ['OWN', 'OWN_OR_DELEGATED'])->exists()) {
            throw new RuntimeException('Cannot remove scope variants while assignments still reference them.');
        }
        DB::statement('ALTER TABLE access_scope_limits DROP CONSTRAINT access_scope_limits_scope_check');
        DB::statement("ALTER TABLE access_scope_limits ADD CONSTRAINT access_scope_limits_scope_check CHECK (scope IN ('MY_UNITS'))");
    }
};
