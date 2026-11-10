<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0181: the database backstop for "a delivery cannot be dated in the future" used the session's `current_date + 1`, which is the database/UTC calendar day. The company
 * calendar day can be one day ahead of it (the other way round for zones west of UTC), so the backstop could refuse a date the application correctly accepts, or allow one a day
 * late. The function now takes the day from the company's own time zone. Only the function body changes; no data, no table shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE quotation_document_deliveries IN ACCESS EXCLUSIVE MODE');
        DB::unprepared($this->function('(now() AT TIME ZONE (SELECT c.timezone FROM companies c WHERE c.id = NEW.company_id))::date + 1'));
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE quotation_document_deliveries IN ACCESS EXCLUSIVE MODE');
        DB::unprepared($this->function('current_date + 1'));
    }

    private function function(string $limit): string
    {
        return <<<SQL
CREATE OR REPLACE FUNCTION quotation_document_deliveries_insert_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
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
 IF NEW.delivered_on > ({$limit}) THEN
  RAISE EXCEPTION 'A delivery cannot be dated in the future' USING ERRCODE = '23514';
 END IF;
 NEW.recorded_at := clock_timestamp();
 RETURN NEW;
END \$\$;
SQL;
    }
};
