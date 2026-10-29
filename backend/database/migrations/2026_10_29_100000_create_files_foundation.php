<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Files F0 (ADR 0153, issue #49): the disabled shared file storage kernel — identities and evidence for stored objects, and nothing that stores, serves or shares a
 * file for a user. Bytes are never in PostgreSQL; a row names an opaque key on the private `files` disk.
 *
 *  - FILE_BLOBS are immutable object identities: company, disk, an opaque random key (never derived from content or a name), SHA-256, size and the sniffed media
 *    type. Only the health (STAGED, VERIFIED, MISSING, QUARANTINED, REJECTED) and the scan state may change, by legal transitions, and REJECTED is final. There is
 *    deliberately no unique constraint on the hash: nothing is deduplicated and nothing is shared across companies (D9).
 *  - FILE_BLOB_EVIDENCE is the append-only trail of every health or scan change, with the actor or a named service and the database's clock.
 *  - FILE_UPLOAD_INTENTS are the durable, idempotent record that a given owner/purpose asked to upload; a mutable projection with a guarded state machine. The
 *    COMMITTED state exists for the attachment slice (F1) and is unreachable here.
 *  - FILE_OPERATION_RECEIPTS make a retried operation replay instead of repeat.
 *  - Tenancy is by composite keys. Every table refuses UPDATE/DELETE (the two mutable ones only their legal moves) and TRUNCATE; times come from the database.
 *
 * No route, grant, duty, attachment, download or URL accompanies these tables. Rolling back refuses while any row exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_blobs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('storage_disk', 40);
            $t->string('storage_key', 64);
            $t->char('sha256', 64);
            $t->unsignedBigInteger('size_bytes');
            $t->string('detected_media_type', 120);
            $t->string('declared_media_type', 120)->nullable();
            $t->string('health', 12);
            $t->string('scan_state', 16);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestampTz('created_at', 6);
            $t->timestampTz('verified_at', 6)->nullable();
            $t->unique(['company_id', 'id'], 'file_blobs_company_id_unique');
            $t->unique(['storage_disk', 'storage_key'], 'file_blobs_storage_unique');
            $t->foreign(['company_id', 'created_by'], 'file_blobs_created_by_fk')->references(['company_id', 'id'])->on('users');
            $t->index(['company_id', 'health'], 'file_blobs_by_health');
        });
        DB::statement("ALTER TABLE file_blobs ADD CONSTRAINT file_blobs_shape CHECK (
            storage_key ~ '^[0-9a-f]{40}\$' AND sha256 ~ '^[0-9a-f]{64}\$' AND length(trim(storage_disk)) > 0 AND length(trim(detected_media_type)) > 0
            AND (declared_media_type IS NULL OR length(trim(declared_media_type)) > 0)
            AND health IN ('STAGED','VERIFIED','MISSING','QUARANTINED','REJECTED') AND scan_state IN ('NOT_CONFIGURED','PENDING','CLEAN','INFECTED','ERROR')
        )");

        Schema::create('file_blob_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('blob_id');
            $t->string('event', 20);
            $t->string('from_health', 12)->nullable();
            $t->string('to_health', 12);
            $t->char('expected_sha256', 64)->nullable();
            $t->char('observed_sha256', 64)->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('service', 60)->nullable();
            $t->string('reason', 255)->nullable();
            $t->jsonb('evidence');
            $t->timestampTz('occurred_at', 6);
            $t->unique(['company_id', 'id'], 'file_blob_events_company_id_unique');
            $t->foreign(['company_id', 'blob_id'], 'file_blob_events_blob_fk')->references(['company_id', 'id'])->on('file_blobs');
            $t->foreign(['company_id', 'actor_id'], 'file_blob_events_actor_fk')->references(['company_id', 'id'])->on('users');
            $t->index(['company_id', 'blob_id', 'id'], 'file_blob_events_by_blob');
        });
        DB::statement("ALTER TABLE file_blob_events ADD CONSTRAINT file_blob_events_shape CHECK (
            event IN ('STAGED','VERIFIED','SCAN_RESULT','QUARANTINED','RELEASED','MISSING_DETECTED','RESTORED','RECONCILED','REJECTED')
            AND to_health IN ('STAGED','VERIFIED','MISSING','QUARANTINED','REJECTED') AND (from_health IS NULL OR from_health IN ('STAGED','VERIFIED','MISSING','QUARANTINED','REJECTED'))
            AND (expected_sha256 IS NULL OR expected_sha256 ~ '^[0-9a-f]{64}\$') AND (observed_sha256 IS NULL OR observed_sha256 ~ '^[0-9a-f]{64}\$')
            AND (actor_id IS NOT NULL OR (service IS NOT NULL AND length(trim(service)) > 0)) AND (reason IS NULL OR length(trim(reason)) > 0)
            AND jsonb_typeof(evidence) = 'object' AND octet_length(evidence::text) <= 4096
        )");

        Schema::create('file_upload_intents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('owner_type_key', 120);
            $t->string('owner_binding_identity', 120);
            $t->string('purpose_key', 120);
            $t->unsignedBigInteger('actor_id');
            $t->string('idempotency_key', 120);
            $t->char('intent_digest', 64);
            $t->unsignedBigInteger('expected_size');
            $t->char('expected_sha256', 64)->nullable();
            $t->string('state', 12)->default('PENDING');
            $t->string('error_code', 60)->nullable();
            $t->unsignedBigInteger('blob_id')->nullable();
            $t->timestampTz('expires_at', 6);
            $t->timestampTz('created_at', 6);
            $t->timestampTz('updated_at', 6);
            $t->unique(['company_id', 'id'], 'file_upload_intents_company_id_unique');
            $t->unique(['company_id', 'idempotency_key'], 'file_upload_intents_key_unique');
            $t->foreign(['company_id', 'actor_id'], 'file_upload_intents_actor_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'blob_id'], 'file_upload_intents_blob_fk')->references(['company_id', 'id'])->on('file_blobs');
            $t->index(['company_id', 'state'], 'file_upload_intents_by_state');
        });
        DB::statement("ALTER TABLE file_upload_intents ADD CONSTRAINT file_upload_intents_shape CHECK (
            owner_type_key ~ '^[a-z0-9]+(\\.[a-z0-9-]+)+\$' AND purpose_key ~ '^[a-z0-9]+(\\.[a-z0-9_-]+)+\$' AND length(trim(owner_binding_identity)) > 0 AND length(trim(idempotency_key)) > 0
            AND intent_digest ~ '^[0-9a-f]{64}\$' AND (expected_sha256 IS NULL OR expected_sha256 ~ '^[0-9a-f]{64}\$') AND expected_size > 0
            AND state IN ('PENDING','STAGED','COMMITTED','EXPIRED','ABORTED') AND (error_code IS NULL OR (state = 'ABORTED' AND length(trim(error_code)) > 0))
            AND (state <> 'ABORTED' OR error_code IS NOT NULL) AND (state NOT IN ('STAGED','COMMITTED') OR blob_id IS NOT NULL)
        )");

        Schema::create('file_operation_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('operation', 60);
            $t->string('operation_key', 120);
            $t->char('intent_digest', 64);
            $t->unsignedBigInteger('intent_id')->nullable();
            $t->unsignedBigInteger('blob_id')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->timestampTz('recorded_at', 6);
            $t->unique(['company_id', 'id'], 'file_operation_receipts_company_id_unique');
            $t->unique(['company_id', 'operation', 'operation_key'], 'file_operation_receipts_key_unique');
            $t->foreign(['company_id', 'intent_id'], 'file_operation_receipts_intent_fk')->references(['company_id', 'id'])->on('file_upload_intents');
            $t->foreign(['company_id', 'blob_id'], 'file_operation_receipts_blob_fk')->references(['company_id', 'id'])->on('file_blobs');
            $t->foreign(['company_id', 'actor_id'], 'file_operation_receipts_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE file_operation_receipts ADD CONSTRAINT file_operation_receipts_shape CHECK (
            length(trim(operation)) > 0 AND length(trim(operation_key)) > 0 AND intent_digest ~ '^[0-9a-f]{64}\$'
        )");

        DB::unprepared(<<<'SQL'
-- Evidence and receipts are history: never updated, never deleted; and no Files table is ever truncated.
CREATE OR REPLACE FUNCTION files_append_only_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION '% rows are append-only', TG_TABLE_NAME USING ERRCODE = '23514';
END $$;
CREATE TRIGGER file_blob_events_guard BEFORE UPDATE OR DELETE ON file_blob_events FOR EACH ROW EXECUTE FUNCTION files_append_only_guard();
CREATE TRIGGER file_operation_receipts_guard BEFORE UPDATE OR DELETE ON file_operation_receipts FOR EACH ROW EXECUTE FUNCTION files_append_only_guard();
CREATE TRIGGER file_blobs_truncate_guard BEFORE TRUNCATE ON file_blobs FOR EACH STATEMENT EXECUTE FUNCTION files_append_only_guard();
CREATE TRIGGER file_blob_events_truncate_guard BEFORE TRUNCATE ON file_blob_events FOR EACH STATEMENT EXECUTE FUNCTION files_append_only_guard();
CREATE TRIGGER file_upload_intents_truncate_guard BEFORE TRUNCATE ON file_upload_intents FOR EACH STATEMENT EXECUTE FUNCTION files_append_only_guard();
CREATE TRIGGER file_operation_receipts_truncate_guard BEFORE TRUNCATE ON file_operation_receipts FOR EACH STATEMENT EXECUTE FUNCTION files_append_only_guard();

-- Times are the database's clock, whatever the writer supplied.
CREATE OR REPLACE FUNCTION files_insert_clock() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME = 'file_blob_events' THEN NEW.occurred_at := clock_timestamp();
 ELSIF TG_TABLE_NAME = 'file_operation_receipts' THEN NEW.recorded_at := clock_timestamp();
 ELSIF TG_TABLE_NAME = 'file_blobs' THEN
  NEW.created_at := clock_timestamp();
  IF NEW.health = 'VERIFIED' THEN NEW.verified_at := NEW.created_at; ELSE NEW.verified_at := NULL; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER file_blob_events_clock BEFORE INSERT ON file_blob_events FOR EACH ROW EXECUTE FUNCTION files_insert_clock();
CREATE TRIGGER file_operation_receipts_clock BEFORE INSERT ON file_operation_receipts FOR EACH ROW EXECUTE FUNCTION files_insert_clock();
CREATE TRIGGER file_blobs_clock BEFORE INSERT ON file_blobs FOR EACH ROW EXECUTE FUNCTION files_insert_clock();

-- A blob is an immutable identity: only its health and scan state move, by the legal transitions; REJECTED is final; VERIFIED stamps the database clock; never deleted.
CREATE OR REPLACE FUNCTION files_blob_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'file_blobs rows are history and cannot be deleted' USING ERRCODE = '23514'; END IF;
 IF ROW(NEW.company_id, NEW.storage_disk, NEW.storage_key, NEW.sha256, NEW.size_bytes, NEW.detected_media_type, NEW.declared_media_type, NEW.created_by, NEW.created_at)
    IS DISTINCT FROM ROW(OLD.company_id, OLD.storage_disk, OLD.storage_key, OLD.sha256, OLD.size_bytes, OLD.detected_media_type, OLD.declared_media_type, OLD.created_by, OLD.created_at) THEN
  RAISE EXCEPTION 'A file blob keeps its identity: company, object, hash, size and type' USING ERRCODE = '23514';
 END IF;
 IF NEW.health <> OLD.health AND NOT ((OLD.health, NEW.health) IN (('STAGED','VERIFIED'),('STAGED','QUARANTINED'),('STAGED','REJECTED'),('VERIFIED','MISSING'),('VERIFIED','QUARANTINED'),
    ('MISSING','VERIFIED'),('QUARANTINED','VERIFIED'),('QUARANTINED','REJECTED'))) THEN
  RAISE EXCEPTION 'A file blob cannot move from % to %', OLD.health, NEW.health USING ERRCODE = '23514';
 END IF;
 IF NEW.health = 'VERIFIED' AND OLD.health <> 'VERIFIED' THEN NEW.verified_at := clock_timestamp(); END IF;
 IF NEW.health <> 'VERIFIED' AND NEW.verified_at IS DISTINCT FROM OLD.verified_at THEN NEW.verified_at := OLD.verified_at; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER file_blobs_guard BEFORE UPDATE OR DELETE ON file_blobs FOR EACH ROW EXECUTE FUNCTION files_blob_guard();

-- An intent is born PENDING with no blob and a future expiry; afterwards only state, error and blob move by legal transitions. COMMITTED belongs to the attachment
-- slice (F1) and is refused here. Never deleted.
CREATE OR REPLACE FUNCTION files_intent_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'file_upload_intents rows are history and cannot be deleted' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'INSERT' THEN
  NEW.created_at := clock_timestamp();
  NEW.updated_at := NEW.created_at;
  IF NEW.state <> 'PENDING' OR NEW.blob_id IS NOT NULL OR NEW.error_code IS NOT NULL OR NEW.expires_at <= NEW.created_at THEN
   RAISE EXCEPTION 'An upload intent is created PENDING, without a blob, and not already expired' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 IF ROW(NEW.company_id, NEW.owner_type_key, NEW.owner_binding_identity, NEW.purpose_key, NEW.actor_id, NEW.idempotency_key, NEW.intent_digest, NEW.expected_size, NEW.expected_sha256, NEW.expires_at, NEW.created_at)
    IS DISTINCT FROM ROW(OLD.company_id, OLD.owner_type_key, OLD.owner_binding_identity, OLD.purpose_key, OLD.actor_id, OLD.idempotency_key, OLD.intent_digest, OLD.expected_size, OLD.expected_sha256, OLD.expires_at, OLD.created_at) THEN
  RAISE EXCEPTION 'An upload intent keeps its identity' USING ERRCODE = '23514';
 END IF;
 IF NEW.state = 'COMMITTED' THEN RAISE EXCEPTION 'An upload intent is committed by the attachment slice, which does not exist yet' USING ERRCODE = '23514'; END IF;
 IF NEW.state <> OLD.state AND NOT ((OLD.state, NEW.state) IN (('PENDING','STAGED'),('PENDING','EXPIRED'),('PENDING','ABORTED'))) THEN
  RAISE EXCEPTION 'An upload intent cannot move from % to %', OLD.state, NEW.state USING ERRCODE = '23514';
 END IF;
 IF OLD.state <> 'PENDING' AND (NEW.blob_id IS DISTINCT FROM OLD.blob_id OR NEW.error_code IS DISTINCT FROM OLD.error_code) THEN
  RAISE EXCEPTION 'A settled upload intent keeps its blob and outcome' USING ERRCODE = '23514';
 END IF;
 NEW.updated_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER file_upload_intents_guard BEFORE INSERT OR UPDATE OR DELETE ON file_upload_intents FOR EACH ROW EXECUTE FUNCTION files_intent_guard();
SQL);
    }

    public function down(): void
    {
        // Lock first, then look: an unlocked pre-check could be overtaken by an insert between the check and the drops.
        DB::statement('LOCK TABLE file_operation_receipts, file_upload_intents, file_blob_events, file_blobs IN ACCESS EXCLUSIVE MODE');
        foreach (['file_operation_receipts', 'file_upload_intents', 'file_blob_events', 'file_blobs'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("{$table} has rows; rolling this migration back would erase file evidence. Plan a backout first.");
            }
        }
        Schema::dropIfExists('file_operation_receipts');
        Schema::dropIfExists('file_upload_intents');
        Schema::dropIfExists('file_blob_events');
        Schema::dropIfExists('file_blobs');
        foreach (['files_intent_guard', 'files_blob_guard', 'files_insert_clock', 'files_append_only_guard'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
    }
};
