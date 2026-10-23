<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, DOC0-Sales skeleton (ADR 0137, DF0 handoff "Ownership boundaries"): the Sales-owned side of a quotation document.
 *
 *  - `quotation_customer_document_snapshots`: the frozen customer-facing projection of an issued revision (schema version, the projection itself, its digest).
 *    The table exists so the contract can be proven end to end; nothing in the application writes it yet — freezing from a real quotation belongs to QL3/DF2.
 *  - `quotation_document_bindings`: the real company-scoped foreign keys Documents deliberately does not hold — to the snapshot (and so to the revision) and to
 *    Documents' `document_subject_bindings` identity. One Sales row per Documents identity.
 * Both are history: the database refuses updates and deletes, and refuses a binding whose Documents identity is not the one this snapshot and audience name.
 * No issue, number, template, renderer, route or permission lives here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_customer_document_snapshots', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('family_id');
            $t->unsignedBigInteger('revision_id');
            $t->unsignedInteger('schema_version');
            $t->jsonb('projection');
            $t->char('projection_digest', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id'], 'quotation_document_snapshots_company_id_unique');
            $t->unique(['company_id', 'revision_id', 'schema_version'], 'quotation_document_snapshots_revision_unique');
            $t->foreign(['company_id', 'family_id', 'revision_id'], 'quotation_document_snapshots_revision_fk')->references(['company_id', 'family_id', 'id'])->on('quotation_revisions');
        });
        DB::statement("ALTER TABLE quotation_customer_document_snapshots ADD CONSTRAINT quotation_document_snapshots_shape CHECK (
            schema_version >= 1 AND jsonb_typeof(projection) = 'object' AND projection_digest ~ '^[0-9a-f]{64}\$'
        )");

        Schema::create('quotation_document_bindings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('snapshot_id');
            $t->unsignedBigInteger('document_binding_id');
            $t->string('audience', 40);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id'], 'quotation_document_bindings_company_id_unique');
            $t->unique(['company_id', 'document_binding_id'], 'quotation_document_bindings_document_unique');
            $t->unique(['company_id', 'snapshot_id', 'audience'], 'quotation_document_bindings_snapshot_audience_unique');
            $t->foreign(['company_id', 'snapshot_id'], 'quotation_document_bindings_snapshot_fk')->references(['company_id', 'id'])->on('quotation_customer_document_snapshots');
            $t->foreign(['company_id', 'document_binding_id'], 'quotation_document_bindings_document_fk')->references(['company_id', 'id'])->on('document_subject_bindings');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quotation_document_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION '% rows are append-only', TG_TABLE_NAME USING ERRCODE = '23514';
END $$;
CREATE TRIGGER quotation_document_snapshots_guard BEFORE UPDATE OR DELETE ON quotation_customer_document_snapshots FOR EACH ROW EXECUTE FUNCTION quotation_document_append_only();
CREATE TRIGGER quotation_document_bindings_guard BEFORE UPDATE OR DELETE ON quotation_document_bindings FOR EACH ROW EXECUTE FUNCTION quotation_document_append_only();

-- The Documents identity must be the one this snapshot names: the quotation customer type, the same audience, owner identity "quotation-snapshot:<id>", generation 1.
CREATE OR REPLACE FUNCTION quotation_document_binding_identity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE doc record; snapshot_version integer;
BEGIN
 SELECT type_key, schema_version, audience, owner_binding_identity, source_generation INTO doc
  FROM document_subject_bindings WHERE company_id = NEW.company_id AND id = NEW.document_binding_id;
 SELECT schema_version INTO snapshot_version FROM quotation_customer_document_snapshots WHERE company_id = NEW.company_id AND id = NEW.snapshot_id;
 IF doc.type_key IS DISTINCT FROM 'sales.quotation.customer' OR doc.audience IS DISTINCT FROM NEW.audience
    OR doc.owner_binding_identity IS DISTINCT FROM 'quotation-snapshot:' || NEW.snapshot_id OR doc.source_generation IS DISTINCT FROM 1
    OR doc.schema_version IS DISTINCT FROM snapshot_version THEN
  RAISE EXCEPTION 'The document binding does not identify this quotation snapshot and audience' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_document_binding_identity BEFORE INSERT ON quotation_document_bindings FOR EACH ROW EXECUTE FUNCTION quotation_document_binding_identity();
SQL);
    }

    public function down(): void
    {
        // Refuse rather than erase document evidence.
        if (Schema::hasTable('quotation_customer_document_snapshots') && (DB::table('quotation_customer_document_snapshots')->exists() || DB::table('quotation_document_bindings')->exists())) {
            throw new RuntimeException('Quotation document snapshots or bindings exist; rolling this migration back would erase document evidence. Plan a backout first.');
        }
        DB::statement('DROP TRIGGER IF EXISTS quotation_document_binding_identity ON quotation_document_bindings');
        DB::statement('DROP TRIGGER IF EXISTS quotation_document_bindings_guard ON quotation_document_bindings');
        DB::statement('DROP TRIGGER IF EXISTS quotation_document_snapshots_guard ON quotation_customer_document_snapshots');
        DB::statement('DROP FUNCTION IF EXISTS quotation_document_binding_identity()');
        DB::statement('DROP FUNCTION IF EXISTS quotation_document_append_only()');
        Schema::dropIfExists('quotation_document_bindings');
        Schema::dropIfExists('quotation_customer_document_snapshots');
    }
};
