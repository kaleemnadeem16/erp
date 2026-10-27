<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, DF2 (document-print-framework slice DF2, ADR 0137, issue #26): freeze what a document is made from, and record the durable intent to generate it —
 * and nothing that renders, stores bytes or opens a door.
 *
 *  - A MANIFEST pins, at the owner's snapshot event, the typed payload and its schema version, the source generation, the template and page policy exactly as
 *    resolved (the configuration itself, not only a version id, so a later change of either cannot alter it), the brand state, assets, fonts, renderer contract
 *    and the formatting. It is insert-only from birth and its time is the database clock. One manifest exists per (binding, purpose, stable generation identity):
 *    a second, different freeze of the same identity is refused by the Action as a conflict, and a duplicate cannot be written by anyone.
 *  - A GENERATION INTENT is the durable record that this manifest is to be generated. It is a mutable projection with a guarded state machine and a monotone
 *    fencing generation; DF2 writes it PENDING and the renderer slice (DF3) owns every later transition. A retry is a new intent under another operation
 *    identity, so evidence is never rewritten.
 *  - The Company default activation chain (DF1a) becomes database-enforced: each activation continues the previous one and is_downgrade is derived, not asserted.
 *  - Tenancy is by composite keys; used configuration versions are referenced by composite keys, so a pinned version cannot be deleted.
 *
 * No route, grant, duty, renderer, byte or blob accompanies these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_manifests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('subject_binding_id');
            $t->string('purpose', 16);
            $t->string('stable_generation_identity', 120);
            $t->char('input_digest', 64);
            $t->jsonb('frozen_payload');
            $t->unsignedInteger('schema_version');
            $t->unsignedInteger('source_generation');
            $t->string('source_snapshot_reference', 255)->nullable();
            $t->jsonb('resolved_template');
            $t->jsonb('resolved_page_policy');
            $t->unsignedBigInteger('template_version_id')->nullable();
            $t->unsignedBigInteger('page_policy_version_id')->nullable();
            $t->string('brand_state', 16);
            $t->jsonb('asset_manifest');
            $t->jsonb('fonts');
            $t->string('renderer_contract', 60);
            $t->string('renderer_version', 40);
            $t->jsonb('locale_formatting');
            $t->char('pinned_manifest_digest', 64);
            $t->unsignedBigInteger('initiator_id')->nullable();
            $t->timestampTz('recorded_at', 6);
            $t->unique(['company_id', 'id'], 'document_manifests_company_id_unique');
            $t->unique(['company_id', 'subject_binding_id', 'purpose', 'stable_generation_identity'], 'document_manifests_identity_unique');
            $t->foreign(['company_id', 'subject_binding_id'], 'document_manifests_binding_fk')->references(['company_id', 'id'])->on('document_subject_bindings');
            $t->foreign(['company_id', 'template_version_id'], 'document_manifests_template_fk')->references(['company_id', 'id'])->on('document_template_versions');
            $t->foreign(['company_id', 'page_policy_version_id'], 'document_manifests_page_policy_fk')->references(['company_id', 'id'])->on('document_page_policy_versions');
            $t->foreign(['company_id', 'initiator_id'], 'document_manifests_initiator_fk')->references(['company_id', 'id'])->on('users');
            $t->index(['company_id', 'template_version_id'], 'document_manifests_by_template');
            $t->index(['company_id', 'page_policy_version_id'], 'document_manifests_by_page_policy');
        });
        DB::statement("ALTER TABLE document_manifests ADD CONSTRAINT document_manifests_shape CHECK (
            purpose IN ('DRAFT_PREVIEW','ORIGINAL') AND length(trim(stable_generation_identity)) > 0 AND schema_version >= 1 AND source_generation >= 1
            AND input_digest ~ '^[0-9a-f]{64}\$' AND pinned_manifest_digest ~ '^[0-9a-f]{64}\$'
            AND (source_snapshot_reference IS NULL OR length(trim(source_snapshot_reference)) > 0)
            AND jsonb_typeof(frozen_payload) = 'object' AND octet_length(frozen_payload::text) <= 1048576
            AND jsonb_typeof(resolved_template) = 'object' AND jsonb_typeof(resolved_page_policy) = 'object'
            AND brand_state = 'NOT_AVAILABLE' AND jsonb_typeof(asset_manifest) = 'array' AND jsonb_typeof(fonts) = 'array' AND jsonb_typeof(locale_formatting) = 'object'
            AND length(trim(renderer_contract)) > 0 AND length(trim(renderer_version)) > 0
        )");

        Schema::create('document_generation_intents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('manifest_id');
            $t->string('operation_identity', 120);
            $t->string('request_key', 120);
            $t->char('intent_digest', 64);
            $t->string('state', 12)->default('PENDING');
            $t->timestampTz('lease_expiry', 6)->nullable();
            $t->unsignedBigInteger('fencing_generation')->default(0);
            $t->string('error_code', 60)->nullable();
            $t->timestampTz('created_at', 6);
            $t->timestampTz('updated_at', 6);
            $t->unique(['company_id', 'id'], 'document_generation_intents_company_id_unique');
            $t->unique(['company_id', 'manifest_id', 'operation_identity'], 'document_generation_intents_identity_unique');
            $t->foreign(['company_id', 'manifest_id'], 'document_generation_intents_manifest_fk')->references(['company_id', 'id'])->on('document_manifests');
            $t->index(['company_id', 'state'], 'document_generation_intents_by_state');
        });
        DB::statement("ALTER TABLE document_generation_intents ADD CONSTRAINT document_generation_intents_shape CHECK (
            length(trim(operation_identity)) > 0 AND length(trim(request_key)) > 0 AND intent_digest ~ '^[0-9a-f]{64}\$'
            AND state IN ('PENDING','RUNNING','FAILED','VERIFIED') AND (error_code IS NULL OR (state = 'FAILED' AND length(trim(error_code)) > 0))
            AND (state <> 'FAILED' OR error_code IS NOT NULL) AND (state <> 'RUNNING' OR lease_expiry IS NOT NULL)
        )");

        DB::unprepared(<<<'SQL'
-- Manifests are evidence: never updated, never deleted, never truncated; the time is the database's own clock whatever the writer supplied.
CREATE TRIGGER document_manifests_guard BEFORE UPDATE OR DELETE ON document_manifests FOR EACH ROW EXECUTE FUNCTION documents_append_only_guard();
CREATE TRIGGER document_manifests_truncate_guard BEFORE TRUNCATE ON document_manifests FOR EACH STATEMENT EXECUTE FUNCTION documents_append_only_guard();
CREATE OR REPLACE FUNCTION document_manifest_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 NEW.recorded_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER document_manifest_insert_guard BEFORE INSERT ON document_manifests FOR EACH ROW EXECUTE FUNCTION document_manifest_insert_guard();

-- An intent is born PENDING with no lease and fence 0; afterwards only its state, lease, fence and error move, by the legal transitions, and the fence never
-- goes back. Never deleted, never truncated.
CREATE OR REPLACE FUNCTION document_generation_intent_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'document_generation_intents rows are history and cannot be deleted' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.state <> 'PENDING' OR NEW.fencing_generation <> 0 OR NEW.lease_expiry IS NOT NULL OR NEW.error_code IS NOT NULL THEN
   RAISE EXCEPTION 'A generation intent is created PENDING, unleased, at fence 0' USING ERRCODE = '23514';
  END IF;
  NEW.created_at := clock_timestamp();
  NEW.updated_at := NEW.created_at;
  RETURN NEW;
 END IF;
 IF ROW(NEW.company_id, NEW.manifest_id, NEW.operation_identity, NEW.request_key, NEW.intent_digest, NEW.created_at)
    IS DISTINCT FROM ROW(OLD.company_id, OLD.manifest_id, OLD.operation_identity, OLD.request_key, OLD.intent_digest, OLD.created_at) THEN
  RAISE EXCEPTION 'A generation intent keeps its identity' USING ERRCODE = '23514';
 END IF;
 IF NEW.fencing_generation < OLD.fencing_generation THEN RAISE EXCEPTION 'A generation intent fence only moves forward' USING ERRCODE = '23514'; END IF;
 IF NEW.state <> OLD.state AND NOT ((OLD.state, NEW.state) IN (('PENDING','RUNNING'),('PENDING','FAILED'),('RUNNING','FAILED'),('RUNNING','VERIFIED'),('RUNNING','PENDING'),('FAILED','PENDING'))) THEN
  RAISE EXCEPTION 'A generation intent cannot move from % to %', OLD.state, NEW.state USING ERRCODE = '23514';
 END IF;
 IF NEW.state = 'RUNNING' AND OLD.state <> 'RUNNING' AND NEW.fencing_generation <= OLD.fencing_generation THEN
  RAISE EXCEPTION 'Starting a generation advances its fence' USING ERRCODE = '23514';
 END IF;
 NEW.updated_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER document_generation_intent_guard BEFORE INSERT OR UPDATE OR DELETE ON document_generation_intents FOR EACH ROW EXECUTE FUNCTION document_generation_intent_guard();
CREATE TRIGGER document_generation_intents_truncate_guard BEFORE TRUNCATE ON document_generation_intents FOR EACH STATEMENT EXECUTE FUNCTION documents_append_only_guard();

-- The Company default activation chain: each activation continues the one before it, and the downgrade flag is derived from the versions rather than asserted.
CREATE OR REPLACE FUNCTION document_default_activation_chain_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prior record; new_tpl record; old_tpl record; new_pol record; old_pol record; derived boolean := false;
BEGIN
 IF NEW.generation = 1 THEN
  IF NEW.previous_template_version_id IS NOT NULL OR NEW.previous_page_policy_version_id IS NOT NULL OR NEW.is_downgrade THEN
   RAISE EXCEPTION 'The first activation of a default has no previous version' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 SELECT template_version_id, page_policy_version_id INTO prior FROM document_default_activations
  WHERE company_id = NEW.company_id AND type_key = NEW.type_key AND generation = NEW.generation - 1;
 IF NOT FOUND THEN RAISE EXCEPTION 'An activation continues the previous generation of its default' USING ERRCODE = '23514'; END IF;
 IF NEW.previous_template_version_id IS DISTINCT FROM prior.template_version_id OR NEW.previous_page_policy_version_id IS DISTINCT FROM prior.page_policy_version_id THEN
  RAISE EXCEPTION 'An activation must name the versions the previous activation left active' USING ERRCODE = '23514';
 END IF;
 IF NEW.template_version_id IS NOT NULL AND NEW.previous_template_version_id IS NOT NULL AND NEW.template_version_id <> NEW.previous_template_version_id THEN
  SELECT template_key, version INTO new_tpl FROM document_template_versions WHERE id = NEW.template_version_id;
  SELECT template_key, version INTO old_tpl FROM document_template_versions WHERE id = NEW.previous_template_version_id;
  IF new_tpl.template_key = old_tpl.template_key AND new_tpl.version < old_tpl.version THEN derived := true; END IF;
 END IF;
 IF NEW.page_policy_version_id IS NOT NULL AND NEW.previous_page_policy_version_id IS NOT NULL AND NEW.page_policy_version_id <> NEW.previous_page_policy_version_id THEN
  SELECT policy_key, version INTO new_pol FROM document_page_policy_versions WHERE id = NEW.page_policy_version_id;
  SELECT policy_key, version INTO old_pol FROM document_page_policy_versions WHERE id = NEW.previous_page_policy_version_id;
  IF new_pol.policy_key = old_pol.policy_key AND new_pol.version < old_pol.version THEN derived := true; END IF;
 END IF;
 IF NEW.is_downgrade IS DISTINCT FROM derived THEN
  RAISE EXCEPTION 'The downgrade flag of an activation is derived from the versions it moves between' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER document_default_activation_chain_guard BEFORE INSERT ON document_default_activations FOR EACH ROW EXECUTE FUNCTION document_default_activation_chain_guard();
SQL);
    }

    public function down(): void
    {
        // Lock first, then look: an unlocked pre-check could be overtaken by an insert between the check and the drops.
        DB::statement('LOCK TABLE document_generation_intents, document_manifests IN ACCESS EXCLUSIVE MODE');
        if (DB::table('document_manifests')->exists() || DB::table('document_generation_intents')->exists()) {
            throw new RuntimeException('Document manifests or generation intents exist; rolling this migration back would erase frozen document evidence. Plan a backout first.');
        }
        DB::statement('DROP TRIGGER IF EXISTS document_default_activation_chain_guard ON document_default_activations');
        DB::statement('DROP FUNCTION IF EXISTS document_default_activation_chain_guard()');
        Schema::dropIfExists('document_generation_intents');
        Schema::dropIfExists('document_manifests');
        DB::statement('DROP FUNCTION IF EXISTS document_generation_intent_guard()');
        DB::statement('DROP FUNCTION IF EXISTS document_manifest_insert_guard()');
        // documents_append_only_guard() belongs to DF0 and stays.
    }
};
