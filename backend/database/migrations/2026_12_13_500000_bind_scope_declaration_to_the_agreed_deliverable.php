<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 Batch 1 owner review (follow-up to 2026_12_13_400000): a scope header could still carry free text that contradicted it ("MATERIALS: EXCLUDED" followed by
 * "Materials required for the work"). The system must not guess the meaning of free text, so the agreement STRUCTURE becomes authoritative:
 *  - a SCOPE DECLARATION is the WHOLE agreed text and exactly four lines: MATERIALS, SUBCONTRACTORS, LINE (the issued line id) and DELIVERABLE_SHA256 (the digest of that line's
 *    description, trimmed of " \t\n\r\v"), so the customer-evidenced record states the scope of THAT exact deliverable and has no free-text field to contradict it;
 *  - a text that starts like a declaration but is not exactly that shape is refused, and conditions-only text may contain no keyword line anywhere (its scope stays UNSTATED,
 *    which the release treats as UNKNOWN);
 *  - the stored scope columns must EQUAL the declaration; the line id and digest must match the issued line (SQL computes the sha256 itself).
 *
 * ADDITIVE: one immutable function and a replaced insert guard; no column, row or append-only guard changes. Rows recorded under the 400000 header format are not rewritten:
 * the resolver treats any scope that is not a verified declaration as UNSTATED (UNKNOWN). down() restores the 400000 guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE service_completion_bases IN ACCESS EXCLUSIVE MODE');
        DB::unprepared(self::declarationFunction());
        DB::unprepared(self::guard());
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE service_completion_bases IN ACCESS EXCLUSIVE MODE');
        $previous = require base_path('database/migrations/2026_12_13_400000_derive_completion_basis_scope_from_agreed_text.php');
        DB::unprepared((new ReflectionMethod($previous, 'guard'))->invoke(null));
        DB::statement('DROP FUNCTION service_completion_basis_scope_declaration(text)');
    }

    /** [materials, subcontract, line, digest] when the text is EXACTLY the four-line declaration (LF only); NULL otherwise. */
    private static function declarationFunction(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION service_completion_basis_scope_declaration(t text) RETURNS text[] LANGUAGE sql IMMUTABLE AS $$
 SELECT regexp_match(t, E'^MATERIALS: (EXCLUDED|REQUIRED|UNSTATED)\nSUBCONTRACTORS: (EXCLUDED|REQUIRED|UNSTATED)\nLINE: ([1-9][0-9]*)\nDELIVERABLE_SHA256: ([0-9a-f]{64})$')
$$;
SQL;
    }

    private static function guard(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION service_completion_basis_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ev record; head record; acc record; issued record; consent record; decl text[];
BEGIN
 SELECT * INTO ev FROM customer_decision_evidence WHERE company_id=NEW.company_id AND id=NEW.evidence_id;
 SELECT al.issued_line_id, a.consent_evidence_id INTO acc FROM customer_acceptance_lines al JOIN customer_acceptances a ON (a.company_id,a.id)=(al.company_id,al.acceptance_id)
  WHERE al.company_id=NEW.company_id AND al.issued_line_id=NEW.issued_line_id AND a.case_id=ev.case_id LIMIT 1;
 SELECT * INTO issued FROM quotation_issued_lines WHERE company_id=NEW.company_id AND id=NEW.issued_line_id;
 SELECT * INTO consent FROM customer_decision_evidence WHERE company_id=NEW.company_id AND id=acc.consent_evidence_id;
 IF ev.id IS NULL OR acc.issued_line_id IS NULL OR ev.source_kind='VERBAL' OR ev.authority_verification_basis NOT IN ('DOCUMENT_ON_FILE','CONFIRMED_BY_SELLER')
 THEN RAISE EXCEPTION 'A completion basis must cite written, authority-verified evidence of the case that accepted this line' USING ERRCODE='23514'; END IF;
 IF NEW.terms_source='ISSUED_LINE' THEN
  IF NEW.evidence_id<>acc.consent_evidence_id OR NEW.agreed_text<>btrim(issued.description) OR NEW.materials_scope<>'UNSTATED' OR NEW.subcontract_scope<>'UNSTATED'
  THEN RAISE EXCEPTION 'The issued-line basis is the line description verbatim, cites the acceptance consent evidence and states no scope' USING ERRCODE='23514'; END IF;
 ELSE
  IF NEW.evidence_id=acc.consent_evidence_id OR ev.recorded_at<=consent.recorded_at OR btrim(ev.factual_description)<>NEW.agreed_text
  THEN RAISE EXCEPTION 'New conditions or a scope declaration need their own later customer-agreement evidence whose text equals the agreed text' USING ERRCODE='23514'; END IF;
  IF NEW.agreed_text ~ '^(MATERIALS|SUBCONTRACTORS|LINE|DELIVERABLE_SHA256):' THEN
   decl := service_completion_basis_scope_declaration(NEW.agreed_text);
   IF decl IS NULL OR decl[3]::numeric <> NEW.issued_line_id OR decl[4] <> encode(sha256(convert_to(btrim(issued.description, E' \t\n\r\x0B'), 'UTF8')), 'hex')
      OR NEW.materials_scope<>decl[1] OR NEW.subcontract_scope<>decl[2]
   THEN RAISE EXCEPTION 'A scope declaration must be exactly the four lines for this issued line and the exact digest of its agreed deliverable text, and the stored scope must equal it' USING ERRCODE='23514'; END IF;
  ELSIF NEW.agreed_text ~ E'\n(MATERIALS|SUBCONTRACTORS|LINE|DELIVERABLE_SHA256):' THEN
   RAISE EXCEPTION 'Conditions text may not contain scope keyword lines; a scope is stated only by a complete declaration' USING ERRCODE='23514';
  ELSIF NEW.materials_scope<>'UNSTATED' OR NEW.subcontract_scope<>'UNSTATED' THEN
   RAISE EXCEPTION 'A scope may only be stated by a declaration of the customer-evidenced agreed text' USING ERRCODE='23514';
  END IF;
 END IF;
 SELECT * INTO head FROM service_completion_bases WHERE company_id=NEW.company_id AND issued_line_id=NEW.issued_line_id ORDER BY version DESC LIMIT 1;
 IF (head.id IS NULL AND (NEW.version<>1 OR NEW.predecessor_id IS NOT NULL)) OR (head.id IS NOT NULL AND (NEW.version<>head.version+1 OR NEW.predecessor_id IS DISTINCT FROM head.id))
 THEN RAISE EXCEPTION 'A completion basis must continue the version chain of its issued line' USING ERRCODE='23514'; END IF;
 NEW.recorded_at := clock_timestamp();
 RETURN NEW;
END $$;
SQL;
    }
};
