<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ASSIGNED (Q26, ADR 0192): a limit that reaches only the records a person is named on (today a purchase order's buyer). It is a scope of its own, not a reading of
 * OWN or OWN_OR_DELEGATED, and it grants no capability: it narrows a duty the person already holds. The table already holds the scope as a name, so this only widens the check.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE access_scope_limits IN ACCESS EXCLUSIVE MODE');
        DB::statement('ALTER TABLE access_scope_limits DROP CONSTRAINT access_scope_limits_scope_check');
        DB::statement("ALTER TABLE access_scope_limits ADD CONSTRAINT access_scope_limits_scope_check CHECK (scope IN ('MY_UNITS', 'OWN', 'OWN_OR_DELEGATED', 'ASSIGNED'))");
    }

    /**
     * Rolling back never drops a restriction: while any assignment still carries ASSIGNED the rollback is refused (deleting or rewriting those rows would silently
     * widen someone's access to company-wide). An administrator has to change those limits first.
     */
    public function down(): void
    {
        DB::statement('LOCK TABLE access_scope_limits IN ACCESS EXCLUSIVE MODE');
        if (DB::table('access_scope_limits')->where('scope', 'ASSIGNED')->exists()) {
            throw new RuntimeException('Cannot remove the ASSIGNED scope while assignments still carry it: removing those limits would widen access. Change them first.');
        }
        DB::statement('ALTER TABLE access_scope_limits DROP CONSTRAINT access_scope_limits_scope_check');
        DB::statement("ALTER TABLE access_scope_limits ADD CONSTRAINT access_scope_limits_scope_check CHECK (scope IN ('MY_UNITS', 'OWN', 'OWN_OR_DELEGATED'))");
    }
};
