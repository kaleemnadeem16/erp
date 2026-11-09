<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database integrity (issue #99): a tax source correction names the fact it corrects and its predecessor, but the predecessor key checked the company only. A correction could name
 * the predecessor of a different fact, or skip a version. This trigger makes the database check, under the company's Tax generation row lock (the same row the insert guard
 * already bumps, so concurrent corrections of one company queue up):
 *  - the first version of a fact has no earlier correction of that fact;
 *  - a later version's predecessor is a correction of the SAME fact, and its version is exactly the predecessor's plus one.
 * It adds no table lock and rewrites no rows. Rows written before this migration are not re-judged; any that already break the rule are logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tax_quote_correction_chain_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE pred tax_quotation_source_corrections%ROWTYPE;
BEGIN
 PERFORM 1 FROM tax_generations WHERE company_id = NEW.company_id FOR UPDATE;
 IF NEW.predecessor_id IS NULL THEN
  -- Two plain equality lookups, so each uses its partial (company, fact, version) index; an IS NOT DISTINCT FROM form would scan the table.
  IF (NEW.jurisdiction_fact_id IS NOT NULL AND EXISTS (SELECT 1 FROM tax_quotation_source_corrections c WHERE c.company_id = NEW.company_id AND c.jurisdiction_fact_id = NEW.jurisdiction_fact_id))
   OR (NEW.classification_fact_id IS NOT NULL AND EXISTS (SELECT 1 FROM tax_quotation_source_corrections c WHERE c.company_id = NEW.company_id AND c.classification_fact_id = NEW.classification_fact_id)) THEN
   RAISE EXCEPTION 'A correction chain starts once: name the latest correction as the predecessor' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 SELECT * INTO pred FROM tax_quotation_source_corrections WHERE company_id = NEW.company_id AND id = NEW.predecessor_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'The predecessor correction does not exist' USING ERRCODE = '23503'; END IF;
 IF pred.jurisdiction_fact_id IS DISTINCT FROM NEW.jurisdiction_fact_id OR pred.classification_fact_id IS DISTINCT FROM NEW.classification_fact_id THEN
  RAISE EXCEPTION 'A correction can only supersede a correction of the same fact' USING ERRCODE = '23514';
 END IF;
 IF NEW.version_no <> pred.version_no + 1 THEN
  RAISE EXCEPTION 'A correction is the next version after its predecessor' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER tax_quote_source_write_chain BEFORE INSERT ON tax_quotation_source_corrections FOR EACH ROW EXECUTE FUNCTION tax_quote_correction_chain_guard();
SQL);
        $broken = (int) DB::selectOne('SELECT count(*) AS n FROM tax_quotation_source_corrections c JOIN tax_quotation_source_corrections p ON p.company_id = c.company_id AND p.id = c.predecessor_id
            WHERE p.jurisdiction_fact_id IS DISTINCT FROM c.jurisdiction_fact_id OR p.classification_fact_id IS DISTINCT FROM c.classification_fact_id OR c.version_no <> p.version_no + 1')->n;
        if ($broken > 0) {
            Log::warning("tax_quotation_source_corrections: {$broken} existing correction(s) do not follow the chain rule; the rule applies to new rows only. Review them.");
        }
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE tax_quotation_source_corrections IN ACCESS EXCLUSIVE MODE');
        DB::statement('DROP TRIGGER IF EXISTS tax_quote_source_write_chain ON tax_quotation_source_corrections');
        DB::statement('DROP FUNCTION IF EXISTS tax_quote_correction_chain_guard()');
    }
};
