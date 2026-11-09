<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sales_approval_request_evidence_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='TRUNCATE' THEN
  IF EXISTS(SELECT 1 FROM approval_requests WHERE subject_type='QUOTATION_REVISION') THEN
   RAISE EXCEPTION 'Sales approval evidence is immutable' USING ERRCODE='23514';
  END IF;
  RETURN NULL;
 END IF;
 IF TG_OP='DELETE' THEN
  IF OLD.subject_type='QUOTATION_REVISION' THEN
   RAISE EXCEPTION 'Sales approval evidence is immutable' USING ERRCODE='23514';
  END IF;
  RETURN OLD;
 END IF;
 IF (OLD.subject_type='QUOTATION_REVISION' OR NEW.subject_type='QUOTATION_REVISION') AND
  ROW(NEW.company_id,NEW.subject_type,NEW.subject_id,NEW.policy_id,NEW.submitted_by,NEW.submitted_at,NEW.facts,NEW.facts_hash,NEW.summary)
  IS DISTINCT FROM ROW(OLD.company_id,OLD.subject_type,OLD.subject_id,OLD.policy_id,OLD.submitted_by,OLD.submitted_at,OLD.facts,OLD.facts_hash,OLD.summary) THEN
  RAISE EXCEPTION 'Sales approval evidence is immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_approval_request_evidence_guard BEFORE UPDATE OR DELETE ON approval_requests
 FOR EACH ROW EXECUTE FUNCTION sales_approval_request_evidence_guard();
CREATE TRIGGER sales_approval_request_evidence_truncate BEFORE TRUNCATE ON approval_requests
 FOR EACH STATEMENT EXECUTE FUNCTION sales_approval_request_evidence_guard();
SQL);
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE approval_requests IN ACCESS EXCLUSIVE MODE');
        DB::unprepared('DROP TRIGGER sales_approval_request_evidence_truncate ON approval_requests; DROP TRIGGER sales_approval_request_evidence_guard ON approval_requests; DROP FUNCTION sales_approval_request_evidence_guard();');
    }
};
