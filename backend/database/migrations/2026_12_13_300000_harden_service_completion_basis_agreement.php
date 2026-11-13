<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 Batch 1 correction (#122): the customer-agreed scope must genuinely establish whether materials or subcontractors are required, and new conditions must be
 * supported by their own customer-agreement evidence — an employee cannot invent obligations and cite old general acceptance evidence.
 *
 * ADDITIVE: three columns on `service_completion_bases` with safe defaults (existing rows read as ISSUED_LINE / UNSTATED, which means applicability UNKNOWN) and a replaced
 * insert guard. The append-only UPDATE/DELETE/TRUNCATE guards are untouched; ADD COLUMN with a constant default fires no row trigger.
 *
 * Two ways to record a basis:
 *  - ISSUED_LINE: the agreed text IS the issued line's description, verbatim, citing the acceptance's OWN consent evidence. Scopes stay UNSTATED.
 *  - EVIDENCE_TEXT: new conditions and any EXCLUDED/REQUIRED scope need DEDICATED written, authority-verified evidence of the accepting case that is NOT the acceptance's
 *    own consent evidence, recorded AFTER it, whose factual description equals the agreed text exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE service_completion_bases IN ACCESS EXCLUSIVE MODE');
        $duplicates = DB::selectOne('SELECT count(*) AS n FROM (SELECT 1 FROM service_completion_bases GROUP BY company_id, issued_line_id, evidence_id HAVING count(*) > 1) d');
        if ((int) $duplicates->n > 0) {
            throw new RuntimeException('Completion bases cite the same evidence more than once for one line; resolve them before this migration (a separately approved correction).');
        }
        DB::statement("ALTER TABLE service_completion_bases
            ADD COLUMN materials_scope varchar(12) NOT NULL DEFAULT 'UNSTATED',
            ADD COLUMN subcontract_scope varchar(12) NOT NULL DEFAULT 'UNSTATED',
            ADD COLUMN terms_source varchar(16) NOT NULL DEFAULT 'ISSUED_LINE'");
        DB::statement("ALTER TABLE service_completion_bases ADD CONSTRAINT service_completion_bases_scope_shape CHECK (
            materials_scope IN ('EXCLUDED','REQUIRED','UNSTATED') AND subcontract_scope IN ('EXCLUDED','REQUIRED','UNSTATED')
            AND terms_source IN ('ISSUED_LINE','EVIDENCE_TEXT')
            AND (terms_source = 'EVIDENCE_TEXT' OR (materials_scope = 'UNSTATED' AND subcontract_scope = 'UNSTATED'))
        )");
        // One line cannot cite the same evidence row twice: the evidence fixes the text, so a second record could only flip a scope on the same words.
        DB::statement('CREATE UNIQUE INDEX service_completion_bases_evidence_unique ON service_completion_bases (company_id, issued_line_id, evidence_id)');

        DB::unprepared(self::guard(true));
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE service_completion_bases IN ACCESS EXCLUSIVE MODE');
        if (DB::table('service_completion_bases')->where(fn ($q) => $q->where('terms_source', 'EVIDENCE_TEXT')->orWhere('materials_scope', '<>', 'UNSTATED')->orWhere('subcontract_scope', '<>', 'UNSTATED'))->exists()) {
            throw new RuntimeException('Scope-bearing completion bases exist; separately approved backout required.');
        }
        DB::unprepared(self::guard(false));
        DB::statement('DROP INDEX service_completion_bases_evidence_unique');
        DB::statement('ALTER TABLE service_completion_bases DROP CONSTRAINT service_completion_bases_scope_shape');
        DB::statement('ALTER TABLE service_completion_bases DROP COLUMN materials_scope, DROP COLUMN subcontract_scope, DROP COLUMN terms_source');
    }

    /** The insert guard: the original (cc4c8940) body when $hardened is false, the corrected one when true. */
    private static function guard(bool $hardened): string
    {
        $agreement = $hardened ? <<<'SQL'
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
 END IF;
SQL : <<<'SQL'
 SELECT count(*) INTO accepted FROM customer_acceptance_lines al JOIN customer_acceptances a ON (a.company_id,a.id)=(al.company_id,al.acceptance_id)
  WHERE al.company_id=NEW.company_id AND al.issued_line_id=NEW.issued_line_id AND a.case_id=ev.case_id;
 IF ev.id IS NULL OR accepted=0 OR ev.source_kind='VERBAL' OR ev.authority_verification_basis='UNVERIFIED'
 THEN RAISE EXCEPTION 'A completion basis must cite written, authority-bearing evidence of the case that accepted this line' USING ERRCODE='23514'; END IF;
SQL;
        $declare = $hardened ? 'ev record; head record; acc record; issued record; consent record;' : 'ev record; head record; accepted bigint;';

        return <<<SQL
CREATE OR REPLACE FUNCTION service_completion_basis_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
DECLARE {$declare}
BEGIN
 SELECT * INTO ev FROM customer_decision_evidence WHERE company_id=NEW.company_id AND id=NEW.evidence_id;
{$agreement}
 SELECT * INTO head FROM service_completion_bases WHERE company_id=NEW.company_id AND issued_line_id=NEW.issued_line_id ORDER BY version DESC LIMIT 1;
 IF (head.id IS NULL AND (NEW.version<>1 OR NEW.predecessor_id IS NOT NULL)) OR (head.id IS NOT NULL AND (NEW.version<>head.version+1 OR NEW.predecessor_id IS DISTINCT FROM head.id))
 THEN RAISE EXCEPTION 'A completion basis must continue the version chain of its issued line' USING ERRCODE='23514'; END IF;
 NEW.recorded_at := clock_timestamp();
 RETURN NEW;
END \$\$;
SQL;
    }
};
