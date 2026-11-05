<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4, documents completion: a recorded manual delivery can be CORRECTED or WITHDRAWN without touching the evidence. Additive only.
 *
 *  - `correction_reason` (why the earlier record was wrong) and `is_withdrawal` (the earlier record is withdrawn: the delivery did not happen as recorded).
 *  - A WITHDRAWAL row repeats the values of the record it withdraws (the table keeps its NOT NULL columns and the evidence stays a plain list of statements) and needs a reason.
 *  - A withdrawn record is final: it cannot itself be corrected or withdrawn again (a delivery that really happened is a NEW record).
 *  - Rows written before this migration keep a NULL reason and are never withdrawals; the update/delete/truncate guards of the table are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE quotation_document_deliveries IN ACCESS EXCLUSIVE MODE');
        DB::statement('ALTER TABLE quotation_document_deliveries ADD COLUMN correction_reason varchar(500) NULL, ADD COLUMN is_withdrawal boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE quotation_document_deliveries ADD CONSTRAINT quotation_document_deliveries_correction_shape CHECK (
            (correction_reason IS NULL OR (corrects_id IS NOT NULL AND length(trim(correction_reason)) > 0))
            AND (NOT is_withdrawal OR (corrects_id IS NOT NULL AND correction_reason IS NOT NULL))
        )');
        DB::unprepared(<<<'SQL'
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
  SELECT revision_id, artefact_id, is_withdrawal, channel, delivered_on, recipient_name, recipient_detail, note INTO prior
    FROM quotation_document_deliveries WHERE company_id = NEW.company_id AND id = NEW.corrects_id;
  IF NOT FOUND OR prior.revision_id <> NEW.revision_id OR prior.artefact_id <> NEW.artefact_id THEN
   RAISE EXCEPTION 'A correction concerns the same quotation document as the record it corrects' USING ERRCODE = '23514';
  END IF;
  IF prior.is_withdrawal THEN
   RAISE EXCEPTION 'A withdrawn delivery record is final: record a new delivery instead' USING ERRCODE = '23514';
  END IF;
  IF NEW.is_withdrawal AND (NEW.channel <> prior.channel OR NEW.delivered_on <> prior.delivered_on OR NEW.recipient_name <> prior.recipient_name
     OR NEW.recipient_detail IS DISTINCT FROM prior.recipient_detail OR NEW.note IS DISTINCT FROM prior.note) THEN
   RAISE EXCEPTION 'A withdrawal repeats the record it withdraws' USING ERRCODE = '23514';
  END IF;
 END IF;
 IF NEW.delivered_on > (current_date + 1) THEN
  RAISE EXCEPTION 'A delivery cannot be dated in the future' USING ERRCODE = '23514';
 END IF;
 NEW.recorded_at := clock_timestamp();
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE quotation_document_deliveries IN ACCESS EXCLUSIVE MODE');
        if (DB::table('quotation_document_deliveries')->where(fn ($q) => $q->where('is_withdrawal', true)->orWhereNotNull('correction_reason'))->exists()) {
            throw new RuntimeException('Delivery corrections or withdrawals exist; rolling this migration back would erase their reasons. Plan a backout first.');
        }
        DB::unprepared(<<<'SQL'
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
SQL);
        DB::statement('ALTER TABLE quotation_document_deliveries DROP CONSTRAINT quotation_document_deliveries_correction_shape');
        DB::statement('ALTER TABLE quotation_document_deliveries DROP COLUMN correction_reason, DROP COLUMN is_withdrawal');
    }
};
