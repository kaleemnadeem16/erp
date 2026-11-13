<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 Batch 1 review correction (P1): a completion basis could state EXCLUDED for materials/subcontractors while the cited customer-agreement text said they were
 * required — the scope columns were operator-chosen flags validated separately from the text.
 *
 * The classification now lives INSIDE the customer-evidenced agreed text as a strict two-line header (see ScopeHeader) and the stored scope columns must EQUAL what that header
 * says. The grammar is fixed and mirrored in SQL (`service_completion_basis_header_scopes`), so raw SQL cannot bypass the Action. An EVIDENCE_TEXT basis without a well-formed
 * header is refused; the ISSUED_LINE path stays UNSTATED/UNSTATED. Free text is never interpreted.
 *
 * ADDITIVE: one immutable function and a replaced insert guard; no column, row or append-only guard changes. Preflight refuses if a pre-existing row states a scope its text
 * does not carry (a separately approved correction). down() restores the 2026_12_13_300000 guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE service_completion_bases IN ACCESS EXCLUSIVE MODE');
        DB::unprepared(self::headerFunction());
        $bad = DB::selectOne("SELECT count(*) AS n FROM service_completion_bases b WHERE b.terms_source = 'EVIDENCE_TEXT' AND (b.materials_scope <> 'UNSTATED' OR b.subcontract_scope <> 'UNSTATED')
            AND (service_completion_basis_header_scopes(b.agreed_text) IS NULL
              OR (service_completion_basis_header_scopes(b.agreed_text))[1] <> b.materials_scope OR (service_completion_basis_header_scopes(b.agreed_text))[2] <> b.subcontract_scope)");
        if ((int) $bad->n > 0) {
            throw new RuntimeException('Existing completion bases state a material/subcontractor scope their agreed text does not carry; resolve them before this migration (a separately approved correction).');
        }
        DB::unprepared(self::guard());
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE service_completion_bases IN ACCESS EXCLUSIVE MODE');
        $previous = require base_path('database/migrations/2026_12_13_300000_harden_service_completion_basis_agreement.php');
        $guard = (new ReflectionMethod($previous, 'guard'))->invoke(null, true);
        DB::unprepared($guard);
        DB::statement('DROP FUNCTION service_completion_basis_header_scopes(text)');
    }

    /** [materials, subcontract] when the text starts with exactly the two header lines (LF only) and the free text repeats neither keyword line; NULL otherwise. */
    private static function headerFunction(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION service_completion_basis_header_scopes(t text) RETURNS text[] LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE m text[];
BEGIN
 m := regexp_match(t, '^MATERIALS: (EXCLUDED|REQUIRED|UNSTATED)\nSUBCONTRACTORS: (EXCLUDED|REQUIRED|UNSTATED)(\n[\s\S]*)?$');
 IF m IS NULL THEN RETURN NULL; END IF;
 IF m[3] IS NOT NULL AND m[3] ~ E'\n(MATERIALS|SUBCONTRACTORS):' THEN RETURN NULL; END IF;
 RETURN ARRAY[m[1], m[2]];
END $$;
SQL;
    }

    private static function guard(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION service_completion_basis_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ev record; head record; acc record; issued record; consent record; scopes text[];
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
  scopes := service_completion_basis_header_scopes(NEW.agreed_text);
  IF scopes IS NULL OR NEW.materials_scope<>scopes[1] OR NEW.subcontract_scope<>scopes[2]
  THEN RAISE EXCEPTION 'The stated material/subcontractor scope must equal the strict header of the customer-evidenced agreed text' USING ERRCODE='23514'; END IF;
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
