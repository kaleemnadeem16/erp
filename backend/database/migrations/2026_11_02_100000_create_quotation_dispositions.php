<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QL4/QL5 storage: how an issued quotation revision stops being available. An issued revision and its issue are never edited; a seller's withdrawal, or its whole replacement by
 * a later issued revision of the same family, is appended here, once, with a reason and the database's instant. Expiry is not stored: it is the issue's own expiry instant, read
 * as of now. A successor DRAFT (QL5) names its ISSUED predecessor in the same family, and a family has at most one open successor draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_dispositions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('family_id');
            $t->unsignedBigInteger('revision_id');
            $t->string('kind', 12);
            $t->text('reason');
            $t->unsignedBigInteger('replacement_revision_id')->nullable();
            $t->unsignedBigInteger('actor_id');
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'revision_id'], 'quotation_dispositions_revision_unique');
            $t->foreign(['company_id', 'family_id', 'revision_id'], 'quotation_dispositions_revision_fk')->references(['company_id', 'family_id', 'id'])->on('quotation_revisions');
            $t->foreign(['company_id', 'family_id', 'replacement_revision_id'], 'quotation_dispositions_replacement_fk')->references(['company_id', 'family_id', 'id'])->on('quotation_revisions');
            $t->foreign(['company_id', 'actor_id'], 'quotation_dispositions_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE quotation_dispositions ADD CONSTRAINT quotation_dispositions_shape CHECK (
    kind IN ('WITHDRAWN','REPLACED') AND length(trim(reason)) > 0
    AND (kind = 'REPLACED') = (replacement_revision_id IS NOT NULL) AND replacement_revision_id IS DISTINCT FROM revision_id
);

CREATE OR REPLACE FUNCTION quotation_disposition_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rev_state text; succ record;
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'A quotation disposition is append-only' USING ERRCODE = '23514'; END IF;
 SELECT state INTO rev_state FROM quotation_revisions WHERE company_id = NEW.company_id AND id = NEW.revision_id;
 IF rev_state IS DISTINCT FROM 'ISSUED' THEN RAISE EXCEPTION 'Only an issued quotation revision can be withdrawn or replaced' USING ERRCODE = '23514'; END IF;
 IF NEW.kind = 'REPLACED' THEN
  SELECT state, predecessor_revision_id INTO succ FROM quotation_revisions WHERE company_id = NEW.company_id AND id = NEW.replacement_revision_id;
  IF succ.state IS DISTINCT FROM 'ISSUED' OR succ.predecessor_revision_id IS DISTINCT FROM NEW.revision_id THEN
   RAISE EXCEPTION 'A revision is replaced only by an issued revision that names it as its predecessor' USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_dispositions_guard BEFORE INSERT OR UPDATE OR DELETE ON quotation_dispositions FOR EACH ROW EXECUTE FUNCTION quotation_disposition_guard();
CREATE TRIGGER quotation_dispositions_truncate_guard BEFORE TRUNCATE ON quotation_dispositions FOR EACH STATEMENT EXECUTE FUNCTION quotation_disposition_guard();

-- A successor draft names an ISSUED predecessor of its own family, and a family has one open successor draft at a time.
CREATE OR REPLACE FUNCTION quotation_revision_predecessor_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE pred_state text;
BEGIN
 IF NEW.predecessor_revision_id IS NOT NULL THEN
  SELECT state INTO pred_state FROM quotation_revisions WHERE company_id = NEW.company_id AND family_id = NEW.family_id AND id = NEW.predecessor_revision_id;
  IF pred_state IS DISTINCT FROM 'ISSUED' THEN RAISE EXCEPTION 'A revision succeeds an issued revision of its own family' USING ERRCODE = '23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_revisions_predecessor_guard BEFORE INSERT ON quotation_revisions FOR EACH ROW EXECUTE FUNCTION quotation_revision_predecessor_guard();
CREATE UNIQUE INDEX quotation_revisions_one_open_successor ON quotation_revisions (company_id, family_id) WHERE state = 'DRAFT' AND predecessor_revision_id IS NOT NULL;
SQL);
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE quotation_dispositions, quotation_revisions IN ACCESS EXCLUSIVE MODE');
        if (DB::table('quotation_dispositions')->exists() || DB::table('quotation_revisions')->whereNotNull('predecessor_revision_id')->exists()) {
            throw new RuntimeException('Dispositions or successor revisions exist: rolling back would destroy the record of what was withdrawn or replaced. Plan a separately approved backout first.');
        }
        DB::unprepared('DROP INDEX IF EXISTS quotation_revisions_one_open_successor; DROP TRIGGER IF EXISTS quotation_revisions_predecessor_guard ON quotation_revisions; DROP FUNCTION IF EXISTS quotation_revision_predecessor_guard();');
        Schema::dropIfExists('quotation_dispositions');
        DB::unprepared('DROP FUNCTION IF EXISTS quotation_disposition_guard();');
    }
};
