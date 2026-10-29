<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, DF3 (document-print-framework slice DF3, ADR 0137 and 0153): the stored result of a generation — a verified artefact — and the head that names the current one.
 * Bytes live in the Files module's private store; a row here only ties a verified file blob to the manifest and generation intent that produced it.
 *
 *  - An ARTEFACT is insert-only. The database insists that it is produced by a RUNNING intent at exactly the fence that worker holds (a worker whose lease was lost cannot
 *    publish), that the intent belongs to the artefact's manifest, that the blob is VERIFIED and carries the same hash and size, and that the purpose matches the manifest's.
 *    One artefact per intent and per blob.
 *  - A HEAD names the current artefact of a binding and purpose. An ORIGINAL head is written once and never moves (an original is never replaced); a DRAFT_PREVIEW head
 *    advances one generation at a time and only to an artefact of the same binding and purpose.
 *  - Tenancy is by composite keys. Nothing here renders, serves or deletes a file; no route, grant or duty accompanies these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_artefacts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('manifest_id');
            $t->unsignedBigInteger('intent_id');
            $t->unsignedBigInteger('fence');
            $t->unsignedBigInteger('blob_id');
            $t->string('purpose', 16);
            $t->string('media_type', 120);
            $t->char('sha256', 64);
            $t->unsignedBigInteger('size_bytes');
            $t->unsignedInteger('page_count');
            $t->string('renderer_contract', 60);
            $t->string('renderer_version', 40);
            $t->timestampTz('created_at', 6);
            $t->unique(['company_id', 'id'], 'document_artefacts_company_id_unique');
            $t->unique(['company_id', 'intent_id'], 'document_artefacts_intent_unique');
            $t->unique(['company_id', 'blob_id'], 'document_artefacts_blob_unique');
            $t->foreign(['company_id', 'manifest_id'], 'document_artefacts_manifest_fk')->references(['company_id', 'id'])->on('document_manifests');
            $t->foreign(['company_id', 'intent_id'], 'document_artefacts_intent_fk')->references(['company_id', 'id'])->on('document_generation_intents');
            $t->foreign(['company_id', 'blob_id'], 'document_artefacts_blob_fk')->references(['company_id', 'id'])->on('file_blobs');
            $t->index(['company_id', 'manifest_id'], 'document_artefacts_by_manifest');
        });
        DB::statement("ALTER TABLE document_artefacts ADD CONSTRAINT document_artefacts_shape CHECK (
            purpose IN ('DRAFT_PREVIEW','ORIGINAL') AND media_type = 'application/pdf' AND sha256 ~ '^[0-9a-f]{64}\$' AND size_bytes > 0 AND page_count >= 1 AND fence >= 1
            AND length(trim(renderer_contract)) > 0 AND length(trim(renderer_version)) > 0
        )");

        Schema::create('document_artefact_heads', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('subject_binding_id');
            $t->string('purpose', 16);
            $t->unsignedBigInteger('artefact_id');
            $t->unsignedInteger('generation')->default(1);
            $t->timestampTz('updated_at', 6);
            $t->unique(['company_id', 'id'], 'document_artefact_heads_company_id_unique');
            $t->unique(['company_id', 'subject_binding_id', 'purpose'], 'document_artefact_heads_identity_unique');
            $t->foreign(['company_id', 'subject_binding_id'], 'document_artefact_heads_binding_fk')->references(['company_id', 'id'])->on('document_subject_bindings');
            $t->foreign(['company_id', 'artefact_id'], 'document_artefact_heads_artefact_fk')->references(['company_id', 'id'])->on('document_artefacts');
        });
        DB::statement("ALTER TABLE document_artefact_heads ADD CONSTRAINT document_artefact_heads_shape CHECK (purpose IN ('DRAFT_PREVIEW','ORIGINAL') AND generation >= 1)");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER document_artefacts_guard BEFORE UPDATE OR DELETE ON document_artefacts FOR EACH ROW EXECUTE FUNCTION documents_append_only_guard();
CREATE TRIGGER document_artefacts_truncate_guard BEFORE TRUNCATE ON document_artefacts FOR EACH STATEMENT EXECUTE FUNCTION documents_append_only_guard();
CREATE TRIGGER document_artefact_heads_truncate_guard BEFORE TRUNCATE ON document_artefact_heads FOR EACH STATEMENT EXECUTE FUNCTION documents_append_only_guard();

-- An artefact is published by the worker that holds the intent: the intent is RUNNING at exactly this fence, belongs to this manifest, and the blob is the verified file of
-- the same hash and size. The time is the database's.
CREATE OR REPLACE FUNCTION document_artefact_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE intent record; manifest record; blob record;
BEGIN
 SELECT manifest_id, state, fencing_generation INTO intent FROM document_generation_intents WHERE company_id = NEW.company_id AND id = NEW.intent_id FOR UPDATE;
 IF NOT FOUND OR intent.manifest_id <> NEW.manifest_id THEN RAISE EXCEPTION 'An artefact belongs to the manifest of its generation intent' USING ERRCODE = '23514'; END IF;
 IF intent.state <> 'RUNNING' OR intent.fencing_generation <> NEW.fence THEN RAISE EXCEPTION 'Only the worker that holds the running generation intent can publish its artefact' USING ERRCODE = '23514'; END IF;
 SELECT purpose, renderer_contract, renderer_version INTO manifest FROM document_manifests WHERE company_id = NEW.company_id AND id = NEW.manifest_id;
 IF manifest.purpose <> NEW.purpose OR manifest.renderer_contract <> NEW.renderer_contract OR manifest.renderer_version <> NEW.renderer_version THEN
  RAISE EXCEPTION 'An artefact carries the purpose and renderer its manifest pinned' USING ERRCODE = '23514';
 END IF;
 SELECT health, sha256, size_bytes INTO blob FROM file_blobs WHERE company_id = NEW.company_id AND id = NEW.blob_id;
 IF NOT FOUND OR blob.health <> 'VERIFIED' OR blob.sha256 <> NEW.sha256 OR blob.size_bytes <> NEW.size_bytes THEN
  RAISE EXCEPTION 'An artefact is a verified file with the recorded hash and size' USING ERRCODE = '23514';
 END IF;
 NEW.created_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER document_artefact_insert_guard BEFORE INSERT ON document_artefacts FOR EACH ROW EXECUTE FUNCTION document_artefact_insert_guard();

-- The head names an artefact of its own binding and purpose. An ORIGINAL head is written once and never moves; a draft head advances one generation at a time. Never deleted.
CREATE OR REPLACE FUNCTION document_artefact_head_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE art record;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'document_artefact_heads rows are history and cannot be deleted' USING ERRCODE = '23514'; END IF;
 SELECT a.purpose, m.subject_binding_id INTO art FROM document_artefacts a JOIN document_manifests m ON m.company_id = a.company_id AND m.id = a.manifest_id
  WHERE a.company_id = NEW.company_id AND a.id = NEW.artefact_id;
 IF NOT FOUND OR art.purpose <> NEW.purpose OR art.subject_binding_id <> NEW.subject_binding_id THEN
  RAISE EXCEPTION 'A head names an artefact of its own binding and purpose' USING ERRCODE = '23514';
 END IF;
 IF TG_OP = 'INSERT' THEN
  NEW.generation := 1;
  NEW.updated_at := clock_timestamp();
  RETURN NEW;
 END IF;
 IF ROW(NEW.company_id, NEW.subject_binding_id, NEW.purpose) IS DISTINCT FROM ROW(OLD.company_id, OLD.subject_binding_id, OLD.purpose) THEN
  RAISE EXCEPTION 'A head keeps its binding and purpose' USING ERRCODE = '23514';
 END IF;
 IF OLD.purpose = 'ORIGINAL' THEN RAISE EXCEPTION 'An original is never replaced: its head does not move' USING ERRCODE = '23514'; END IF;
 IF NEW.generation <> OLD.generation + 1 THEN RAISE EXCEPTION 'A head moves forward by exactly one generation' USING ERRCODE = '23514'; END IF;
 NEW.updated_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER document_artefact_head_guard BEFORE INSERT OR UPDATE OR DELETE ON document_artefact_heads FOR EACH ROW EXECUTE FUNCTION document_artefact_head_guard();
SQL);
    }

    public function down(): void
    {
        // Lock first, then look: an unlocked pre-check could be overtaken by an insert between the check and the drops.
        DB::statement('LOCK TABLE document_artefact_heads, document_artefacts IN ACCESS EXCLUSIVE MODE');
        if (DB::table('document_artefacts')->exists() || DB::table('document_artefact_heads')->exists()) {
            throw new RuntimeException('Document artefacts exist; rolling this migration back would erase generated document evidence. Plan a backout first.');
        }
        Schema::dropIfExists('document_artefact_heads');
        Schema::dropIfExists('document_artefacts');
        DB::statement('DROP FUNCTION IF EXISTS document_artefact_head_guard()');
        DB::statement('DROP FUNCTION IF EXISTS document_artefact_insert_guard()');
    }
};
