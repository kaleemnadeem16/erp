<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 follow-up to DF0/DF1a (issue #25): the Documents history guards fire on UPDATE and DELETE but PostgreSQL row triggers never fire on TRUNCATE, which
 * empties a table without touching them. The runtime role (`erp`) is the database owner and a superuser, so revoking TRUNCATE from it would change nothing;
 * a statement-level BEFORE TRUNCATE trigger is the guard that works for every role short of one that disables triggers. Same function as the row guards, so
 * a table added by a later Documents slice reuses it the same way. Additive: no existing migration or table is changed.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'document_subject_bindings',
        'document_operation_receipts',
        'document_template_versions',
        'document_page_policy_versions',
        'document_default_activations',
        'document_company_defaults',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("CREATE TRIGGER {$table}_truncate_guard BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION documents_append_only_guard()");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_truncate_guard ON {$table}");
        }
    }
};
