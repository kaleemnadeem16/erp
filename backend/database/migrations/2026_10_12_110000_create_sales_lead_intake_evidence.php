<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM correction C (ADR 0113): the enquiry as received is immutable evidence, and the six current enquiry fields change only through a reasoned,
 * versioned correction whose exact old/new values are recorded.
 *
 *  - sales_lead_intakes: one immutable row per lead, ORIGINAL_INTAKE for a new lead, or CAPTURED_BASELINE (with the actual capture time and a rollout
 *    reference, never backdated) for leads that existed before this migration. Existing history is not rewritten.
 *  - sales_lead_enquiry_corrections / _fields: append-only header and per-field old/new values; resulting_version = previous_version + 1.
 *  - The database refuses: a lead without its intake at commit, a change of an enquiry field without exactly one version step and matching old/new
 *    evidence, a version step with no changed field, a rewritten receipt date, and any update or delete of the evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $t): void {
            $t->unsignedBigInteger('enquiry_version')->default(0);
        });

        Schema::create('sales_lead_intakes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('lead_id');
            $t->string('evidence_kind', 20);
            $t->timestampTz('captured_at');
            $t->unsignedBigInteger('captured_by')->nullable();
            $t->text('capture_reference')->nullable();
            $t->timestampTz('received_at');
            $t->unsignedBigInteger('recorded_creator_id');
            $t->string('enquirer_name', 200);
            $t->string('enquirer_organisation', 200)->nullable();
            $t->string('enquirer_email', 200)->nullable();
            $t->string('enquirer_phone', 64)->nullable();
            $t->text('description')->nullable();
            $t->string('source', 100)->nullable();
            $t->unique(['company_id', 'lead_id']);
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'lead_id'])->references(['company_id', 'id'])->on('sales_leads');
            $t->foreign(['company_id', 'captured_by'])->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'recorded_creator_id'])->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE sales_lead_intakes ADD CONSTRAINT sales_lead_intakes_shape CHECK (
            evidence_kind IN ('ORIGINAL_INTAKE','CAPTURED_BASELINE') AND length(trim(enquirer_name))>0
            AND (captured_by IS NOT NULL OR (evidence_kind='CAPTURED_BASELINE' AND length(trim(coalesce(capture_reference,'')))>0))
            AND (description IS NULL OR length(description)<=5000)
        )");

        Schema::create('sales_lead_enquiry_corrections', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('lead_id');
            $t->unsignedBigInteger('actor_id');
            $t->timestampTz('recorded_at');
            $t->text('reason');
            $t->unsignedBigInteger('previous_version');
            $t->unsignedBigInteger('resulting_version');
            $t->unique(['company_id', 'lead_id', 'resulting_version'], 'sales_lead_corrections_version_unique');
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'lead_id'], 'sales_lead_corrections_lead_fk')->references(['company_id', 'id'])->on('sales_leads');
            $t->foreign(['company_id', 'actor_id'], 'sales_lead_corrections_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE sales_lead_enquiry_corrections ADD CONSTRAINT sales_lead_enquiry_corrections_shape CHECK (length(trim(reason))>0 AND length(reason)<=1000 AND resulting_version = previous_version + 1)');

        Schema::create('sales_lead_enquiry_correction_fields', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('correction_id');
            $t->string('field_name', 30);
            $t->text('old_value')->nullable();
            $t->text('new_value')->nullable();
            $t->unique(['company_id', 'correction_id', 'field_name'], 'sales_lead_corr_fields_unique');
            $t->foreign(['company_id', 'correction_id'], 'sales_lead_corr_fields_correction_fk')->references(['company_id', 'id'])->on('sales_lead_enquiry_corrections');
        });
        DB::statement("ALTER TABLE sales_lead_enquiry_correction_fields ADD CONSTRAINT sales_lead_enquiry_correction_fields_shape CHECK (
            field_name IN ('enquirer_name','enquirer_organisation','enquirer_email','enquirer_phone','description','source')
            AND old_value IS DISTINCT FROM new_value
            AND (field_name<>'enquirer_name' OR length(trim(coalesce(new_value,'')))>0)
            AND length(coalesce(new_value,'')) <= CASE field_name WHEN 'enquirer_phone' THEN 64 WHEN 'source' THEN 100 WHEN 'description' THEN 5000 ELSE 200 END
        )");

        // Leads that exist today have no provable original: label what is captured now, with its real capture time and the rollout reference.
        DB::statement("INSERT INTO sales_lead_intakes (company_id, lead_id, evidence_kind, captured_at, captured_by, capture_reference, received_at, recorded_creator_id, enquirer_name, enquirer_organisation, enquirer_email, enquirer_phone, description, source)
            SELECT l.company_id, l.id, 'CAPTURED_BASELINE', now(), NULL, 'CRM correction C rollout 2026-10-12: current enquiry captured as the baseline; no earlier original is provable', l.received_at, l.created_by, l.enquirer_name, l.enquirer_organisation, l.enquirer_email, l.enquirer_phone, l.description, l.source
            FROM sales_leads l WHERE NOT EXISTS (SELECT 1 FROM sales_lead_intakes i WHERE i.company_id = l.company_id AND i.lead_id = l.id)");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sales_lead_evidence_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Lead intake and enquiry-correction evidence is append-only';
END $$;
CREATE TRIGGER sales_lead_intakes_append_only BEFORE UPDATE OR DELETE ON sales_lead_intakes FOR EACH ROW EXECUTE FUNCTION sales_lead_evidence_append_only();
CREATE TRIGGER sales_lead_enquiry_corrections_append_only BEFORE UPDATE OR DELETE ON sales_lead_enquiry_corrections FOR EACH ROW EXECUTE FUNCTION sales_lead_evidence_append_only();
CREATE TRIGGER sales_lead_enquiry_correction_fields_append_only BEFORE UPDATE OR DELETE ON sales_lead_enquiry_correction_fields FOR EACH ROW EXECUTE FUNCTION sales_lead_evidence_append_only();

-- The enquiry values change only with exactly one version step, and a version step needs a changed value; the receipt date never changes.
CREATE OR REPLACE FUNCTION sales_lead_enquiry_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE changed boolean;
BEGIN
 IF NEW.received_at IS DISTINCT FROM OLD.received_at THEN
  RAISE EXCEPTION 'The date an enquiry was received is never rewritten';
 END IF;
 changed := NEW.enquirer_name IS DISTINCT FROM OLD.enquirer_name OR NEW.enquirer_organisation IS DISTINCT FROM OLD.enquirer_organisation
  OR NEW.enquirer_email IS DISTINCT FROM OLD.enquirer_email OR NEW.enquirer_phone IS DISTINCT FROM OLD.enquirer_phone
  OR NEW.description IS DISTINCT FROM OLD.description OR NEW.source IS DISTINCT FROM OLD.source;
 IF changed AND NEW.enquiry_version <> OLD.enquiry_version + 1 THEN
  RAISE EXCEPTION 'An enquiry detail changes only with its version advanced by exactly one';
 END IF;
 IF NOT changed AND NEW.enquiry_version <> OLD.enquiry_version THEN
  RAISE EXCEPTION 'The enquiry version advances only when an enquiry detail actually changes';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_lead_enquiry_guard BEFORE UPDATE ON sales_leads FOR EACH ROW EXECUTE FUNCTION sales_lead_enquiry_guard();

-- At commit: every changed value has its exact old/new evidence under the matching correction, and nothing else.
CREATE OR REPLACE FUNCTION sales_lead_enquiry_evidence_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cid bigint; expected int := 0; found int;
BEGIN
 IF NEW.enquiry_version = OLD.enquiry_version THEN RETURN NULL; END IF;
 SELECT id INTO cid FROM sales_lead_enquiry_corrections WHERE company_id = NEW.company_id AND lead_id = NEW.id AND previous_version = OLD.enquiry_version AND resulting_version = NEW.enquiry_version;
 IF cid IS NULL THEN RAISE EXCEPTION 'An enquiry correction needs its evidence for version %', NEW.enquiry_version; END IF;
 IF NEW.enquirer_name IS DISTINCT FROM OLD.enquirer_name THEN expected := expected + 1; PERFORM sales_lead_field_evidence(cid, NEW.company_id, 'enquirer_name', OLD.enquirer_name, NEW.enquirer_name); END IF;
 IF NEW.enquirer_organisation IS DISTINCT FROM OLD.enquirer_organisation THEN expected := expected + 1; PERFORM sales_lead_field_evidence(cid, NEW.company_id, 'enquirer_organisation', OLD.enquirer_organisation, NEW.enquirer_organisation); END IF;
 IF NEW.enquirer_email IS DISTINCT FROM OLD.enquirer_email THEN expected := expected + 1; PERFORM sales_lead_field_evidence(cid, NEW.company_id, 'enquirer_email', OLD.enquirer_email, NEW.enquirer_email); END IF;
 IF NEW.enquirer_phone IS DISTINCT FROM OLD.enquirer_phone THEN expected := expected + 1; PERFORM sales_lead_field_evidence(cid, NEW.company_id, 'enquirer_phone', OLD.enquirer_phone, NEW.enquirer_phone); END IF;
 IF NEW.description IS DISTINCT FROM OLD.description THEN expected := expected + 1; PERFORM sales_lead_field_evidence(cid, NEW.company_id, 'description', OLD.description, NEW.description); END IF;
 IF NEW.source IS DISTINCT FROM OLD.source THEN expected := expected + 1; PERFORM sales_lead_field_evidence(cid, NEW.company_id, 'source', OLD.source, NEW.source); END IF;
 SELECT count(*) INTO found FROM sales_lead_enquiry_correction_fields WHERE company_id = NEW.company_id AND correction_id = cid;
 IF found <> expected THEN RAISE EXCEPTION 'The correction records % changed details but % were changed', found, expected; END IF;
 RETURN NULL;
END $$;
CREATE OR REPLACE FUNCTION sales_lead_field_evidence(cid bigint, cmp bigint, fname text, oldv text, newv text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM sales_lead_enquiry_correction_fields WHERE company_id = cmp AND correction_id = cid AND field_name = fname AND old_value IS NOT DISTINCT FROM oldv AND new_value IS NOT DISTINCT FROM newv) THEN
  RAISE EXCEPTION 'The correction does not record the exact old and new value of %', fname;
 END IF;
END $$;
CREATE CONSTRAINT TRIGGER sales_lead_enquiry_evidence_check AFTER UPDATE ON sales_leads DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sales_lead_enquiry_evidence_check();

-- A correction cannot exist without the projection it explains.
CREATE OR REPLACE FUNCTION sales_lead_correction_projection_check() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM sales_leads WHERE company_id = NEW.company_id AND id = NEW.lead_id AND enquiry_version >= NEW.resulting_version) THEN
  RAISE EXCEPTION 'An enquiry correction exists only together with the enquiry version it produced';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER sales_lead_correction_projection_check AFTER INSERT ON sales_lead_enquiry_corrections DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sales_lead_correction_projection_check();

-- Every lead has its intake evidence by the time its receipt commits.
CREATE OR REPLACE FUNCTION sales_lead_intake_required() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM sales_lead_intakes WHERE company_id = NEW.company_id AND lead_id = NEW.id) THEN
  RAISE EXCEPTION 'A lead needs its immutable intake evidence';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER sales_lead_intake_required AFTER INSERT ON sales_leads DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sales_lead_intake_required();
SQL);
    }

    public function down(): void
    {
        if (DB::table('sales_lead_enquiry_corrections')->exists() || DB::table('sales_lead_intakes')->where('evidence_kind', 'ORIGINAL_INTAKE')->exists()) {
            throw new RuntimeException('Refusing to remove lead intake evidence: accepted original intakes or enquiry corrections would be lost, and the corrected enquiry would no longer be auditable.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS sales_lead_intake_required ON sales_leads;
DROP TRIGGER IF EXISTS sales_lead_enquiry_evidence_check ON sales_leads;
DROP TRIGGER IF EXISTS sales_lead_enquiry_guard ON sales_leads;
DROP TRIGGER IF EXISTS sales_lead_correction_projection_check ON sales_lead_enquiry_corrections;
DROP FUNCTION IF EXISTS sales_lead_intake_required();
DROP FUNCTION IF EXISTS sales_lead_correction_projection_check();
DROP FUNCTION IF EXISTS sales_lead_enquiry_evidence_check();
DROP FUNCTION IF EXISTS sales_lead_field_evidence(bigint, bigint, text, text, text);
DROP FUNCTION IF EXISTS sales_lead_enquiry_guard();
SQL);
        // The baseline rows are append-only: lift the guard only to remove what this migration itself captured.
        DB::unprepared('DROP TRIGGER IF EXISTS sales_lead_intakes_append_only ON sales_lead_intakes');
        Schema::dropIfExists('sales_lead_enquiry_correction_fields');
        Schema::dropIfExists('sales_lead_enquiry_corrections');
        Schema::dropIfExists('sales_lead_intakes');
        DB::unprepared('DROP FUNCTION IF EXISTS sales_lead_evidence_append_only()');
        Schema::table('sales_leads', function (Blueprint $t): void {
            $t->dropColumn('enquiry_version');
        });
    }
};
