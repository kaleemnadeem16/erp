<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE quotation_issues ADD COLUMN authority_evaluation_id bigint;
ALTER TABLE quotation_issues ADD CONSTRAINT quotation_issue_authority_evaluation_fk
 FOREIGN KEY(company_id, authority_evaluation_id) REFERENCES sales_discount_evaluations(company_id,id);
CREATE OR REPLACE FUNCTION quotation_issue_authority_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE mode_now text;
BEGIN
 SELECT mode INTO mode_now FROM sales_discount_mode_events WHERE company_id=NEW.company_id ORDER BY id DESC LIMIT 1;
 IF mode_now='ENFORCE' AND NEW.authority_evaluation_id IS NULL THEN
  RAISE EXCEPTION 'Current discount authority evidence is required';
 END IF;
 IF NEW.authority_evaluation_id IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM sales_discount_evaluations e JOIN quotation_revisions r ON r.company_id=e.company_id AND r.id=e.revision_id
  WHERE e.company_id=NEW.company_id AND e.id=NEW.authority_evaluation_id AND e.revision_id=NEW.revision_id
   AND e.input_generation=r.input_generation AND e.actor_id=NEW.issued_by
   AND (mode_now IS DISTINCT FROM 'ENFORCE' OR (e.mode='ENFORCE'
    AND e.authority_generation=(SELECT generation FROM sales_authority_heads WHERE company_id=NEW.company_id)
    AND (e.result IN ('ROUTINE','NOT_APPLICABLE') OR (e.result='APPROVAL_REQUIRED' AND NEW.approval_request_id IS NOT NULL))))
 ) THEN RAISE EXCEPTION 'Discount authority evidence does not belong to this issue'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_issue_authority_guard BEFORE INSERT ON quotation_issues FOR EACH ROW EXECUTE FUNCTION quotation_issue_authority_guard();
SQL);
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE quotation_issues IN ACCESS EXCLUSIVE MODE');
        if (DB::table('quotation_issues')->whereNotNull('authority_evaluation_id')->exists()) {
            throw new RuntimeException('Cannot remove pinned quotation authority evidence.');
        }
        DB::unprepared('DROP TRIGGER quotation_issue_authority_guard ON quotation_issues; DROP FUNCTION quotation_issue_authority_guard(); ALTER TABLE quotation_issues DROP COLUMN authority_evaluation_id;');
    }
};
