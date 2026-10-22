<?php

namespace Tests\Feature\Modules\Documents;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #25: TRUNCATE skips row triggers and the runtime role may TRUNCATE (it is the owner and a superuser), so each Documents history table carries a
 * statement-level BEFORE TRUNCATE guard on the same function as its row guards.
 */
class DocumentsTruncateGuardTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'document_subject_bindings', 'document_operation_receipts', 'document_template_versions',
        'document_page_policy_versions', 'document_default_activations', 'document_company_defaults',
    ];

    /** A table another table references is also refused by PostgreSQL itself unless truncated together with it; the guard is what refuses that case. */
    private function assertRefused(Closure $statement, bool $guardMustBeTheReason = true): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression($guardMustBeTheReason ? '/append-only/' : '/append-only|cannot truncate a table referenced/', $e->getMessage());

            return;
        }
        $this->fail('The database accepted a TRUNCATE that should have been refused.');
    }

    public function test_the_runtime_role_can_truncate_so_the_guard_is_needed(): void
    {
        // The finding behind the guard: privileges alone do not protect these tables from the role the application runs as.
        $role = DB::selectOne('select rolsuper from pg_roles where rolname = current_user');
        $this->assertTrue((bool) $role->rolsuper, 'if this ever becomes false, revoking TRUNCATE becomes an option as well; the guard stays valid either way');
        foreach (self::TABLES as $table) {
            $this->assertTrue((bool) DB::selectOne('select has_table_privilege(current_user, ?, ?) as ok', ["public.{$table}", 'TRUNCATE'])->ok, $table);
        }
    }

    public function test_truncate_is_refused_on_every_documents_history_table_even_when_empty(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertRefused(fn () => DB::statement("TRUNCATE TABLE {$table}"), guardMustBeTheReason: false);
        }
        // Truncated together, no foreign key stands in the way; only the guard does.
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE '.implode(', ', self::TABLES)));
        foreach (['document_operation_receipts, document_subject_bindings', 'document_default_activations, document_company_defaults, document_template_versions, document_page_policy_versions'] as $set) {
            $this->assertRefused(fn () => DB::statement("TRUNCATE TABLE {$set}"));
        }
    }

    public function test_truncate_is_refused_with_rows_present_and_leaves_them(): void
    {
        DB::table('document_subject_bindings')->insert(['company_id' => 1, 'type_key' => 'a.b', 'schema_version' => 1, 'audience' => 'X', 'owner_binding_identity' => 'i', 'source_generation' => 1, 'bound_at' => now()]);
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE document_subject_bindings'), guardMustBeTheReason: false);
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE document_subject_bindings, document_operation_receipts'));
        $this->assertSame(1, DB::table('document_subject_bindings')->count());
    }

    public function test_a_cascading_truncate_from_a_parent_table_is_refused_too(): void
    {
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE companies CASCADE'));
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE users CASCADE'));
    }

    public function test_each_table_has_one_truncate_trigger_on_the_documents_guard(): void
    {
        $rows = DB::select("select c.relname as tbl, p.proname as fn from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid
            where not t.tgisinternal and (t.tgtype & 32) <> 0 and c.relname like 'document\_%' order by 1");
        $expected = self::TABLES;
        sort($expected);
        $this->assertSame($expected, collect($rows)->pluck('tbl')->all());
        foreach ($rows as $row) {
            $this->assertSame('documents_append_only_guard', $row->fn);
        }
    }
}
