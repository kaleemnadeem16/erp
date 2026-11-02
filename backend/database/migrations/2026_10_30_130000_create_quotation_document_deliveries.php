<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, DOC4: the evidence that someone handed a quotation's official PDF to the customer BY HAND OR OUTSIDE THE SYSTEM — who, to whom, by which channel, on which actual
 * date. Sales owns it. It is a statement by a person, NOT receipt, reading or acceptance by the customer, and the system sends nothing. Insert-only: a mistake is
 * corrected by a new row that names the one it corrects, never by an edit.
 *
 *  - The artefact must be an ORIGINAL of this very revision (the database checks the document binding's owner identity), so a draft preview or another quotation's file
 *    can never be recorded as delivered.
 *  - A repeated request (same actor and key) is the same record; a different request under the same key is refused by the Action from the stored digest.
 *  - The time recorded is the database's; the actual delivery date is the person's claim and cannot be in the future (one day of slack for time zones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_document_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('revision_id');
            $t->unsignedBigInteger('artefact_id');
            $t->unsignedBigInteger('recorded_by');
            $t->string('channel', 16);
            $t->date('delivered_on');
            $t->string('recipient_name', 200);
            $t->string('recipient_detail', 200)->nullable();
            $t->string('note', 1000)->nullable();
            $t->unsignedBigInteger('corrects_id')->nullable();
            $t->string('operation_key', 120);
            $t->char('intent_digest', 64);
            $t->timestampTz('recorded_at', 6);
            $t->unique(['company_id', 'id'], 'quotation_document_deliveries_company_id_unique');
            $t->unique(['company_id', 'recorded_by', 'operation_key'], 'quotation_document_deliveries_key_unique');
            $t->unique(['company_id', 'corrects_id'], 'quotation_document_deliveries_one_correction_unique');
            $t->foreign(['company_id', 'revision_id'], 'quotation_document_deliveries_revision_fk')->references(['company_id', 'id'])->on('quotation_revisions');
            $t->foreign(['company_id', 'artefact_id'], 'quotation_document_deliveries_artefact_fk')->references(['company_id', 'id'])->on('document_artefacts');
            $t->foreign(['company_id', 'recorded_by'], 'quotation_document_deliveries_actor_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'corrects_id'], 'quotation_document_deliveries_corrects_fk')->references(['company_id', 'id'])->on('quotation_document_deliveries');
            $t->index(['company_id', 'revision_id', 'id'], 'quotation_document_deliveries_by_revision');
        });
        DB::statement("ALTER TABLE quotation_document_deliveries ADD CONSTRAINT quotation_document_deliveries_shape CHECK (
            channel IN ('HAND','COURIER','POST','OTHER') AND length(trim(recipient_name)) > 0 AND length(trim(operation_key)) > 0 AND intent_digest ~ '^[0-9a-f]{64}\$'
            AND (recipient_detail IS NULL OR length(trim(recipient_detail)) > 0) AND (note IS NULL OR length(trim(note)) > 0)
        )");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quotation_document_deliveries_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Quotation document delivery records are append-only: correct a mistake with a new record' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER quotation_document_deliveries_guard BEFORE UPDATE OR DELETE ON quotation_document_deliveries FOR EACH ROW EXECUTE FUNCTION quotation_document_deliveries_guard();
CREATE TRIGGER quotation_document_deliveries_truncate_guard BEFORE TRUNCATE ON quotation_document_deliveries FOR EACH STATEMENT EXECUTE FUNCTION quotation_document_deliveries_guard();

-- The artefact is the ORIGINAL of this very revision; a correction concerns the same revision and artefact; the date is not in the future; the time is the database's.
CREATE OR REPLACE FUNCTION quotation_document_deliveries_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE art record; prior record;
BEGIN
 SELECT m.purpose, b.owner_binding_identity INTO art FROM document_artefacts a
   JOIN document_manifests m ON m.company_id = a.company_id AND m.id = a.manifest_id
   JOIN document_subject_bindings b ON b.company_id = m.company_id AND b.id = m.subject_binding_id
  WHERE a.company_id = NEW.company_id AND a.id = NEW.artefact_id;
 IF NOT FOUND OR art.purpose <> 'ORIGINAL' OR art.owner_binding_identity <> 'quotation-revision:' || NEW.revision_id THEN
  RAISE EXCEPTION 'A delivery is recorded only against the original document of its own quotation' USING ERRCODE = '23514';
 END IF;
 IF NEW.corrects_id IS NOT NULL THEN
  SELECT revision_id, artefact_id INTO prior FROM quotation_document_deliveries WHERE company_id = NEW.company_id AND id = NEW.corrects_id;
  IF NOT FOUND OR prior.revision_id <> NEW.revision_id OR prior.artefact_id <> NEW.artefact_id THEN
   RAISE EXCEPTION 'A correction concerns the same quotation document as the record it corrects' USING ERRCODE = '23514';
  END IF;
 END IF;
 IF NEW.delivered_on > (current_date + 1) THEN
  RAISE EXCEPTION 'A delivery cannot be dated in the future' USING ERRCODE = '23514';
 END IF;
 NEW.recorded_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_document_deliveries_insert_guard BEFORE INSERT ON quotation_document_deliveries FOR EACH ROW EXECUTE FUNCTION quotation_document_deliveries_insert_guard();
SQL);
    }

    public function down(): void
    {
        // Lock first, then look: an unlocked pre-check could be overtaken by an insert between the check and the drops.
        DB::statement('LOCK TABLE quotation_document_deliveries IN ACCESS EXCLUSIVE MODE');
        if (DB::table('quotation_document_deliveries')->exists()) {
            throw new RuntimeException('Quotation document delivery records exist; rolling this migration back would erase delivery evidence. Plan a backout first.');
        }
        Schema::dropIfExists('quotation_document_deliveries');
        DB::unprepared('DROP FUNCTION IF EXISTS quotation_document_deliveries_insert_guard(); DROP FUNCTION IF EXISTS quotation_document_deliveries_guard();');
    }
};
