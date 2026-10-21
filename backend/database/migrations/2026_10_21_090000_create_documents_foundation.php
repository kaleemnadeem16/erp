<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, DF0 (document-print-framework slice DF0, ADR 0137): the shared Documents common model's first tenant-safe tables — and nothing that is not yet
 * knowable.
 *
 *  - A SUBJECT BINDING names one document type (a module adapter's registered key and schema version), one audience profile, the owner's opaque binding
 *    identity and the source generation it was bound at. It never holds a subject FK: the owning module's own binding table carries real FKs to this row and to
 *    the actual subject (documents.md). One binding per type/audience/identity/generation, so no second archive identity can be minted for the same document.
 *  - An OPERATION RECEIPT records that an operation key was applied with a given intent digest and what it resolved to. The same key replays the original
 *    result; a changed intent under it is a conflict.
 *  - Both are history: the database refuses updates and deletes. Templates, manifests, artefacts and runs arrive with DF1–DF3 and reuse the same guard.
 *  - Tenancy is by composite keys. A binding's actor and a receipt's binding must belong to the row's own company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_subject_bindings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('type_key', 120);
            $t->unsignedInteger('schema_version');
            $t->string('audience', 40);
            $t->string('owner_binding_identity', 120);
            $t->unsignedInteger('source_generation');
            $t->unsignedBigInteger('bound_by')->nullable();
            $t->timestampTz('bound_at');
            $t->unique(['company_id', 'id'], 'document_subject_bindings_company_id_unique');
            $t->unique(['company_id', 'type_key', 'audience', 'owner_binding_identity', 'source_generation'], 'document_subject_bindings_identity_unique');
            $t->foreign(['company_id', 'bound_by'], 'document_subject_bindings_bound_by_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE document_subject_bindings ADD CONSTRAINT document_subject_bindings_shape CHECK (
            type_key ~ '^[a-z0-9]+(\\.[a-z0-9-]+)+$' AND schema_version >= 1 AND source_generation >= 1
            AND length(trim(audience)) > 0 AND length(trim(owner_binding_identity)) > 0
        )");

        Schema::create('document_operation_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('operation', 60);
            $t->string('operation_key', 120);
            $t->char('intent_digest', 64);
            $t->unsignedBigInteger('binding_id');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->timestampTz('recorded_at');
            $t->unique(['company_id', 'id'], 'document_operation_receipts_company_id_unique');
            $t->unique(['company_id', 'operation', 'operation_key'], 'document_operation_receipts_key_unique');
            $t->foreign(['company_id', 'binding_id'], 'document_operation_receipts_binding_fk')->references(['company_id', 'id'])->on('document_subject_bindings');
            $t->foreign(['company_id', 'actor_id'], 'document_operation_receipts_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE document_operation_receipts ADD CONSTRAINT document_operation_receipts_shape CHECK (
            intent_digest ~ '^[0-9a-f]{64}$' AND length(trim(operation)) > 0 AND length(trim(operation_key)) > 0
        )");

        DB::unprepared(<<<'SQL'
-- Used Documents records are evidence: never updated, never deleted. Reused by every Documents table that becomes immutable once used.
CREATE OR REPLACE FUNCTION documents_append_only_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION '% rows are append-only', TG_TABLE_NAME USING ERRCODE = '23514';
END $$;
CREATE TRIGGER document_subject_bindings_guard BEFORE UPDATE OR DELETE ON document_subject_bindings FOR EACH ROW EXECUTE FUNCTION documents_append_only_guard();
CREATE TRIGGER document_operation_receipts_guard BEFORE UPDATE OR DELETE ON document_operation_receipts FOR EACH ROW EXECUTE FUNCTION documents_append_only_guard();
SQL);
    }

    public function down(): void
    {
        // Refuse rather than erase document evidence.
        if (Schema::hasTable('document_subject_bindings') && DB::table('document_subject_bindings')->exists()) {
            throw new RuntimeException('Document bindings exist; rolling this migration back would erase document evidence. Plan a backout first.');
        }
        DB::statement('DROP TRIGGER IF EXISTS document_operation_receipts_guard ON document_operation_receipts');
        DB::statement('DROP TRIGGER IF EXISTS document_subject_bindings_guard ON document_subject_bindings');
        DB::statement('DROP FUNCTION IF EXISTS documents_append_only_guard()');
        Schema::dropIfExists('document_operation_receipts');
        Schema::dropIfExists('document_subject_bindings');
    }
};
