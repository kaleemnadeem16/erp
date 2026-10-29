<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QL3 storage (ADR 0123): the immutable record of an issued quotation revision. No Action, route or permission accompanies it here (`IssueQuotation` is the next commit).
 *
 *  - `quotation_revisions` gains the ISSUED state and the facts that make an issue official: the number, its Numbering allocation, the database's own issue instant, the
 *    issuer and the frozen validity (the company-local zone and the exact expiry instant). An issued revision is immutable: no field, no draft line, no delete.
 *  - `quotation_issues`: exactly one row per issued revision — the reviewed input generation, the maker (revision creator) and checker (issuer), the pinned rounding / tax /
 *    controls evidence references, the settled document totals and a fingerprint of the frozen lines. Append-only.
 *  - `quotation_issued_lines`: the frozen, settled lines (net, tax, gross, disposition, evidence reference) of that issue. Append-only.
 *
 * The database also checks, at COMMIT, that an issue is whole: the revision is ISSUED with the same number, instant, issuer and reviewed generation; the Numbering allocation
 * exists for this company, this document type, this revision and this number; the frozen lines are exactly the draft's lines; and the totals are the sums of the lines that
 * count. A failure after any stage therefore commits no half-issue. Rolling back refuses while any issue exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_revisions', function (Blueprint $t): void {
            $t->string('official_number', 100)->nullable();
            $t->unsignedBigInteger('numbering_allocation_id')->nullable();
            $t->timestampTz('issued_at', 6)->nullable();
            $t->unsignedBigInteger('issued_by')->nullable();
            $t->string('issued_timezone', 64)->nullable();
            $t->timestampTz('expires_at', 6)->nullable();
            $t->foreign(['company_id', 'issued_by'], 'quotation_revisions_issued_by_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement('CREATE UNIQUE INDEX quotation_revisions_official_number_unique ON quotation_revisions (company_id, official_number) WHERE official_number IS NOT NULL');
        DB::statement('ALTER TABLE quotation_revisions DROP CONSTRAINT quotation_revisions_shape');
        DB::statement("ALTER TABLE quotation_revisions ADD CONSTRAINT quotation_revisions_shape CHECK (
            state IN ('DRAFT','CANCELLED_DRAFT','ISSUED') AND input_generation >= 1 AND (revision_ordinal IS NULL OR revision_ordinal >= 1)
            AND (state = 'CANCELLED_DRAFT') = (cancelled_at IS NOT NULL)
            AND (cancelled_at IS NULL) = (cancelled_by IS NULL)
            AND (state = 'DRAFT' OR state = 'ISSUED' OR length(trim(coalesce(cancellation_reason, ''))) > 0)
            AND (state = 'CANCELLED_DRAFT' OR cancellation_reason IS NULL)
            AND ((state = 'ISSUED') = (issued_at IS NOT NULL))
            AND ((state = 'ISSUED') = (official_number IS NOT NULL AND length(trim(official_number)) > 0))
            AND ((state = 'ISSUED') = (numbering_allocation_id IS NOT NULL))
            AND ((state = 'ISSUED') = (issued_by IS NOT NULL))
            AND ((state = 'ISSUED') = (issued_timezone IS NOT NULL AND length(trim(issued_timezone)) > 0))
            AND ((state = 'ISSUED') = (expires_at IS NOT NULL))
            AND (state <> 'ISSUED' OR (recipient_party_id IS NOT NULL AND pricing_date IS NOT NULL AND valid_through_local_date IS NOT NULL AND revision_ordinal IS NOT NULL))
        )");

        Schema::create('quotation_issues', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('family_id');
            $t->unsignedBigInteger('revision_id');
            $t->unsignedInteger('reviewed_generation');
            $t->unsignedBigInteger('revision_created_by');
            $t->unsignedBigInteger('issued_by');
            $t->timestampTz('issued_at', 6);
            $t->string('official_number', 100);
            $t->unsignedBigInteger('numbering_allocation_id');
            $t->string('policy_version', 20);
            $t->string('unknown_payment_policy', 20);
            $t->foreignId('currency_id')->constrained('currencies');
            $t->date('pricing_date');
            $t->date('tax_estimate_date')->nullable();
            $t->date('valid_through_local_date');
            $t->string('issued_timezone', 64);
            $t->timestampTz('expires_at', 6);
            $t->decimal('net_total', 19, 4);
            $t->decimal('optional_net_total', 19, 4)->nullable();
            $t->decimal('tax_total', 19, 4)->nullable();
            $t->decimal('gross_total', 19, 4)->nullable();
            $t->string('rounding_policy_fingerprint', 100)->nullable();
            $t->unsignedInteger('rounding_policy_version_no')->nullable();
            $t->string('tax_calculation_version', 20)->nullable();
            $t->unsignedBigInteger('tax_generation')->nullable();
            $t->string('tax_evidence_fingerprint', 64)->nullable();
            $t->string('controls_outcome', 12);
            $t->string('controls_fingerprint', 64)->nullable();
            $t->string('financial_fingerprint', 64);
            $t->unique(['company_id', 'id'], 'quotation_issues_company_id_unique');
            $t->unique(['company_id', 'revision_id'], 'quotation_issues_revision_unique');
            $t->unique(['company_id', 'id', 'revision_id'], 'quotation_issues_revision_key_unique');
            $t->unique(['company_id', 'id', 'revision_id', 'family_id'], 'quotation_issues_parent_key_unique');
            $t->unique(['company_id', 'official_number'], 'quotation_issues_number_unique');
            $t->foreign(['company_id', 'family_id'], 'quotation_issues_family_fk')->references(['company_id', 'id'])->on('quotation_families');
            $t->foreign(['company_id', 'family_id', 'revision_id'], 'quotation_issues_revision_fk')->references(['company_id', 'family_id', 'id'])->on('quotation_revisions');
            $t->foreign(['company_id', 'issued_by'], 'quotation_issues_issuer_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'revision_created_by'], 'quotation_issues_maker_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE quotation_issues ADD CONSTRAINT quotation_issues_shape CHECK (
            reviewed_generation >= 1 AND length(trim(official_number)) > 0 AND length(trim(policy_version)) > 0 AND length(trim(issued_timezone)) > 0
            AND unknown_payment_policy IN ('BLOCK') AND controls_outcome IN ('PASS','WARN')
            AND (controls_fingerprint IS NULL OR length(controls_fingerprint) = 64) AND length(financial_fingerprint) = 64
            AND (tax_evidence_fingerprint IS NULL OR length(tax_evidence_fingerprint) = 64)
            AND (tax_total IS NULL) = (gross_total IS NULL)
            AND (tax_total IS NULL OR gross_total = net_total + tax_total)
            AND ((tax_total IS NULL) = (tax_evidence_fingerprint IS NULL AND tax_calculation_version IS NULL AND tax_generation IS NULL))
        )");

        // The parent key an issued line is tied to: its source line, in that very revision and scope (a draft line's scope is already bound to its family).
        Schema::table('quotation_draft_lines', function (Blueprint $t): void {
            $t->unique(['company_id', 'revision_id', 'id', 'logical_scope_id'], 'quotation_draft_lines_parent_key_unique');
        });
        Schema::create('quotation_issued_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('issue_id');
            $t->unsignedBigInteger('revision_id');
            $t->unsignedBigInteger('family_id');
            $t->unsignedBigInteger('source_line_id');
            $t->unsignedBigInteger('logical_scope_id');
            $t->unsignedInteger('sort_order');
            $t->string('section_title', 200)->nullable();
            $t->text('description');
            $t->string('membership', 16);
            $t->unsignedBigInteger('choice_group_id')->nullable();
            $t->boolean('is_baseline');
            $t->boolean('counts_in_total');
            $t->string('source_kind', 20);
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('manual_measure', 100)->nullable();
            $t->decimal('offered_quantity', 19, 4);
            $t->unsignedBigInteger('offered_uom_id')->nullable();
            $t->decimal('unit_price', 19, 4);
            $t->unsignedBigInteger('price_currency_id');
            $t->string('fx_fingerprint', 100)->nullable();
            $t->decimal('net', 19, 4);
            $t->decimal('tax', 19, 4)->nullable();
            $t->decimal('gross', 19, 4)->nullable();
            $t->string('tax_disposition', 20)->nullable();
            $t->string('tax_evidence_reference', 64)->nullable();
            $t->unique(['company_id', 'id'], 'quotation_issued_lines_company_id_unique');
            $t->unique(['company_id', 'issue_id', 'source_line_id'], 'quotation_issued_lines_source_unique');
            $t->foreign(['company_id', 'issue_id', 'revision_id', 'family_id'], 'quotation_issued_lines_issue_fk')->references(['company_id', 'id', 'revision_id', 'family_id'])->on('quotation_issues');
            $t->foreign(['company_id', 'revision_id', 'source_line_id', 'logical_scope_id'], 'quotation_issued_lines_source_fk')->references(['company_id', 'revision_id', 'id', 'logical_scope_id'])->on('quotation_draft_lines');
            $t->foreign(['company_id', 'family_id', 'logical_scope_id'], 'quotation_issued_lines_scope_fk')->references(['company_id', 'family_id', 'id'])->on('quotation_logical_scopes');
        });
        DB::statement("ALTER TABLE quotation_issued_lines ADD CONSTRAINT quotation_issued_lines_shape CHECK (
            membership IN ('BASE','OPTIONAL','CHOOSE_ONE') AND length(trim(description)) > 0 AND offered_quantity > 0
            AND counts_in_total = (membership = 'BASE' OR (membership = 'CHOOSE_ONE' AND is_baseline))
            AND (tax IS NULL) = (gross IS NULL) AND (tax IS NULL) = (tax_disposition IS NULL) AND (tax IS NULL) = (tax_evidence_reference IS NULL)
            AND (tax IS NULL OR gross = net + tax)
            AND (tax_disposition IS NULL OR tax_disposition IN ('TAXABLE','ZERO_RATED','EXEMPT','NOT_APPLICABLE'))
            AND (tax_evidence_reference IS NULL OR length(tax_evidence_reference) = 64)
        )");

        DB::unprepared(<<<'SQL'
-- An issued revision is history: the one way out of DRAFT besides cancelling is ISSUED, and nothing changes after it.
CREATE OR REPLACE FUNCTION quotation_revision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE allocated integer;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Quotation revisions are history and cannot be deleted' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.state <> 'DRAFT' THEN RAISE EXCEPTION 'A quotation revision begins as a draft' USING ERRCODE = '23514'; END IF;
  UPDATE quotation_families SET revision_generation = revision_generation + 1, updated_at = now()
   WHERE company_id = NEW.company_id AND id = NEW.family_id RETURNING revision_generation INTO allocated;
  IF NEW.revision_ordinal IS NOT NULL AND NEW.revision_ordinal <> allocated THEN RAISE EXCEPTION 'The revision ordinal is allocated by the family' USING ERRCODE = '23514'; END IF;
  NEW.revision_ordinal := allocated;
  NEW.input_generation := 1;
  RETURN NEW;
 END IF;
 IF OLD.state = 'ISSUED' THEN RAISE EXCEPTION 'An issued quotation revision is immutable' USING ERRCODE = '23514'; END IF;
 IF OLD.state <> 'DRAFT' THEN RAISE EXCEPTION 'A cancelled quotation draft is immutable' USING ERRCODE = '23514'; END IF;
 IF ROW(NEW.company_id, NEW.family_id, NEW.revision_ordinal, NEW.predecessor_revision_id, NEW.created_by, NEW.created_at)
    IS DISTINCT FROM ROW(OLD.company_id, OLD.family_id, OLD.revision_ordinal, OLD.predecessor_revision_id, OLD.created_by, OLD.created_at) THEN
  RAISE EXCEPTION 'A quotation revision keeps its identity' USING ERRCODE = '23514';
 END IF;
 IF NEW.state = 'ISSUED' THEN
  IF ROW(NEW.recipient_party_id, NEW.currency_id, NEW.pricing_date, NEW.tax_estimate_date, NEW.valid_through_local_date, NEW.input_generation)
     IS DISTINCT FROM ROW(OLD.recipient_party_id, OLD.currency_id, OLD.pricing_date, OLD.tax_estimate_date, OLD.valid_through_local_date, OLD.input_generation) THEN
   RAISE EXCEPTION 'Issuing does not change the reviewed draft' USING ERRCODE = '23514';
  END IF;
  NEW.issued_at := now();
  RETURN NEW;
 END IF;
 IF NEW.input_generation <> OLD.input_generation AND NEW.input_generation <> OLD.input_generation + 1 THEN
  RAISE EXCEPTION 'The input generation only advances one step at a time' USING ERRCODE = '23514';
 END IF;
 IF NEW.state = 'DRAFT' AND ROW(NEW.recipient_party_id, NEW.currency_id, NEW.pricing_date, NEW.tax_estimate_date, NEW.valid_through_local_date)
    IS DISTINCT FROM ROW(OLD.recipient_party_id, OLD.currency_id, OLD.pricing_date, OLD.tax_estimate_date, OLD.valid_through_local_date)
    AND NEW.input_generation = OLD.input_generation THEN
  NEW.input_generation := OLD.input_generation + 1;
 END IF;
 RETURN NEW;
END $$;

-- The issue instant is the database's own transaction time, for the revision and for its issue row alike.
CREATE OR REPLACE FUNCTION quotation_issue_stamp() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rev record;
BEGIN
 SELECT state, company_id INTO rev FROM quotation_revisions WHERE id = NEW.revision_id FOR UPDATE;
 IF rev.state IS DISTINCT FROM 'DRAFT' THEN RAISE EXCEPTION 'Only a draft revision can receive its issue' USING ERRCODE = '23514'; END IF;
 NEW.issued_at := now();
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_issues_stamp BEFORE INSERT ON quotation_issues FOR EACH ROW EXECUTE FUNCTION quotation_issue_stamp();

CREATE OR REPLACE FUNCTION quotation_issued_line_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rev_state text;
BEGIN
 SELECT r.state INTO rev_state FROM quotation_revisions r WHERE r.id = NEW.revision_id;
 IF rev_state IS DISTINCT FROM 'DRAFT' THEN RAISE EXCEPTION 'Issued lines are written with their issue, before the revision becomes issued' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_issued_lines_insert_guard BEFORE INSERT ON quotation_issued_lines FOR EACH ROW EXECUTE FUNCTION quotation_issued_line_insert_guard();

CREATE OR REPLACE FUNCTION quotation_issue_append_only_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'An issued quotation record is append-only' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER quotation_issues_guard BEFORE UPDATE OR DELETE ON quotation_issues FOR EACH ROW EXECUTE FUNCTION quotation_issue_append_only_guard();
CREATE TRIGGER quotation_issues_truncate_guard BEFORE TRUNCATE ON quotation_issues FOR EACH STATEMENT EXECUTE FUNCTION quotation_issue_append_only_guard();
CREATE TRIGGER quotation_issued_lines_guard BEFORE UPDATE OR DELETE ON quotation_issued_lines FOR EACH ROW EXECUTE FUNCTION quotation_issue_append_only_guard();
CREATE TRIGGER quotation_issued_lines_truncate_guard BEFORE TRUNCATE ON quotation_issued_lines FOR EACH STATEMENT EXECUTE FUNCTION quotation_issue_append_only_guard();

-- At COMMIT an issue is whole or absent: the revision, its issue row, the Numbering allocation and the frozen lines agree.
CREATE OR REPLACE FUNCTION quotation_issue_consistency(p_company bigint, p_revision bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE rev record; iss record; alloc record; line_count bigint; draft_count bigint; counted numeric; optional_sum numeric; tax_sum numeric; tax_unknown bigint;
BEGIN
 SELECT * INTO rev FROM quotation_revisions WHERE company_id = p_company AND id = p_revision;
 SELECT * INTO iss FROM quotation_issues WHERE company_id = p_company AND revision_id = p_revision;
 IF rev.id IS NULL THEN RETURN; END IF;
 IF rev.state <> 'ISSUED' THEN
  IF iss.id IS NOT NULL THEN RAISE EXCEPTION 'A quotation issue exists for a revision that is not issued' USING ERRCODE = '23514'; END IF;
  RETURN;
 END IF;
 IF iss.id IS NULL THEN RAISE EXCEPTION 'An issued quotation revision has no issue record' USING ERRCODE = '23514'; END IF;
 IF ROW(iss.official_number, iss.numbering_allocation_id, iss.issued_at, iss.issued_by, iss.reviewed_generation, iss.issued_timezone, iss.expires_at, iss.valid_through_local_date, iss.revision_created_by)
    IS DISTINCT FROM ROW(rev.official_number, rev.numbering_allocation_id, rev.issued_at, rev.issued_by, rev.input_generation, rev.issued_timezone, rev.expires_at, rev.valid_through_local_date, rev.created_by)
    OR ROW(iss.currency_id, iss.pricing_date, iss.tax_estimate_date) IS DISTINCT FROM ROW(rev.currency_id, rev.pricing_date, rev.tax_estimate_date) THEN
  RAISE EXCEPTION 'The issue record and the issued revision disagree' USING ERRCODE = '23514';
 END IF;
 SELECT company_id, doc_type, document_id, number INTO alloc FROM numbering_allocations WHERE id = rev.numbering_allocation_id;
 IF alloc.company_id IS DISTINCT FROM p_company OR alloc.doc_type IS DISTINCT FROM 'QUOTATION' OR alloc.document_id IS DISTINCT FROM p_revision OR alloc.number IS DISTINCT FROM rev.official_number THEN
  RAISE EXCEPTION 'The official number does not match its Numbering allocation' USING ERRCODE = '23514';
 END IF;
 SELECT count(*), COALESCE(sum(net) FILTER (WHERE counts_in_total), 0), COALESCE(sum(net) FILTER (WHERE membership = 'OPTIONAL'), 0),
        COALESCE(sum(tax) FILTER (WHERE counts_in_total), 0), count(*) FILTER (WHERE counts_in_total AND tax IS NULL)
   INTO line_count, counted, optional_sum, tax_sum, tax_unknown FROM quotation_issued_lines WHERE company_id = p_company AND issue_id = iss.id;
 SELECT count(*) INTO draft_count FROM quotation_draft_lines WHERE company_id = p_company AND revision_id = p_revision;
 IF line_count = 0 OR line_count <> draft_count THEN RAISE EXCEPTION 'The frozen lines are not exactly the draft lines' USING ERRCODE = '23514'; END IF;
 IF counted <> iss.net_total THEN RAISE EXCEPTION 'The issued net total is not the sum of the counted lines' USING ERRCODE = '23514'; END IF;
 IF iss.optional_net_total IS DISTINCT FROM (CASE WHEN EXISTS (SELECT 1 FROM quotation_issued_lines WHERE issue_id = iss.id AND membership = 'OPTIONAL') THEN optional_sum ELSE NULL END) THEN
  RAISE EXCEPTION 'The issued optional total is not the sum of the optional lines' USING ERRCODE = '23514';
 END IF;
 IF (iss.tax_total IS NULL) <> (tax_unknown > 0) OR (iss.tax_total IS NOT NULL AND iss.tax_total <> tax_sum) THEN
  RAISE EXCEPTION 'The issued tax total is not the sum of the counted line taxes' USING ERRCODE = '23514';
 END IF;
END $$;

CREATE OR REPLACE FUNCTION quotation_issue_consistency_trigger() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME = 'quotation_revisions' THEN
  PERFORM quotation_issue_consistency(NEW.company_id, NEW.id);
 ELSIF TG_TABLE_NAME = 'quotation_issues' THEN
  PERFORM quotation_issue_consistency(NEW.company_id, NEW.revision_id);
 ELSE
  PERFORM quotation_issue_consistency(NEW.company_id, NEW.revision_id);
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER quotation_revisions_issue_check AFTER INSERT OR UPDATE ON quotation_revisions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION quotation_issue_consistency_trigger();
CREATE CONSTRAINT TRIGGER quotation_issues_issue_check AFTER INSERT ON quotation_issues DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION quotation_issue_consistency_trigger();
CREATE CONSTRAINT TRIGGER quotation_issued_lines_issue_check AFTER INSERT ON quotation_issued_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION quotation_issue_consistency_trigger();
SQL);
    }

    public function down(): void
    {
        // Lock first, then look: an unlocked pre-check could be overtaken by an insert between the check and the drops.
        DB::statement('LOCK TABLE quotation_issued_lines, quotation_issues, quotation_revisions IN ACCESS EXCLUSIVE MODE');
        if (DB::table('quotation_issues')->exists() || DB::table('quotation_issued_lines')->exists() || DB::table('quotation_revisions')->where('state', 'ISSUED')->exists()) {
            throw new RuntimeException('Issued quotations exist: rolling back would destroy the official record. Plan a separately approved backout first.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS quotation_revisions_issue_check ON quotation_revisions;
DROP TRIGGER IF EXISTS quotation_issues_issue_check ON quotation_issues;
DROP TRIGGER IF EXISTS quotation_issued_lines_issue_check ON quotation_issued_lines;
SQL);
        Schema::dropIfExists('quotation_issued_lines');
        Schema::table('quotation_draft_lines', fn (Blueprint $t) => $t->dropUnique('quotation_draft_lines_parent_key_unique'));
        Schema::dropIfExists('quotation_issues');
        DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS quotation_issue_consistency_trigger();
DROP FUNCTION IF EXISTS quotation_issue_consistency(bigint, bigint);
DROP FUNCTION IF EXISTS quotation_issue_append_only_guard();
DROP FUNCTION IF EXISTS quotation_issued_line_insert_guard();
DROP FUNCTION IF EXISTS quotation_issue_stamp();
CREATE OR REPLACE FUNCTION quotation_revision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE allocated integer;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Quotation revisions are history and cannot be deleted' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.state <> 'DRAFT' THEN RAISE EXCEPTION 'A quotation revision begins as a draft' USING ERRCODE = '23514'; END IF;
  UPDATE quotation_families SET revision_generation = revision_generation + 1, updated_at = now()
   WHERE company_id = NEW.company_id AND id = NEW.family_id RETURNING revision_generation INTO allocated;
  IF NEW.revision_ordinal IS NOT NULL AND NEW.revision_ordinal <> allocated THEN RAISE EXCEPTION 'The revision ordinal is allocated by the family' USING ERRCODE = '23514'; END IF;
  NEW.revision_ordinal := allocated;
  NEW.input_generation := 1;
  RETURN NEW;
 END IF;
 IF OLD.state <> 'DRAFT' THEN RAISE EXCEPTION 'A cancelled quotation draft is immutable' USING ERRCODE = '23514'; END IF;
 IF ROW(NEW.company_id, NEW.family_id, NEW.revision_ordinal, NEW.predecessor_revision_id, NEW.created_by, NEW.created_at)
    IS DISTINCT FROM ROW(OLD.company_id, OLD.family_id, OLD.revision_ordinal, OLD.predecessor_revision_id, OLD.created_by, OLD.created_at) THEN
  RAISE EXCEPTION 'A quotation revision keeps its identity' USING ERRCODE = '23514';
 END IF;
 IF NEW.input_generation <> OLD.input_generation AND NEW.input_generation <> OLD.input_generation + 1 THEN
  RAISE EXCEPTION 'The input generation only advances one step at a time' USING ERRCODE = '23514';
 END IF;
 IF NEW.state = 'DRAFT' AND ROW(NEW.recipient_party_id, NEW.currency_id, NEW.pricing_date, NEW.tax_estimate_date, NEW.valid_through_local_date)
    IS DISTINCT FROM ROW(OLD.recipient_party_id, OLD.currency_id, OLD.pricing_date, OLD.tax_estimate_date, OLD.valid_through_local_date)
    AND NEW.input_generation = OLD.input_generation THEN
  NEW.input_generation := OLD.input_generation + 1;
 END IF;
 RETURN NEW;
END $$;
SQL);
        DB::statement('DROP INDEX IF EXISTS quotation_revisions_official_number_unique');
        DB::statement('ALTER TABLE quotation_revisions DROP CONSTRAINT quotation_revisions_shape');
        DB::statement("ALTER TABLE quotation_revisions ADD CONSTRAINT quotation_revisions_shape CHECK (
            state IN ('DRAFT','CANCELLED_DRAFT') AND input_generation >= 1 AND (revision_ordinal IS NULL OR revision_ordinal >= 1)
            AND (state = 'CANCELLED_DRAFT') = (cancelled_at IS NOT NULL)
            AND (cancelled_at IS NULL) = (cancelled_by IS NULL)
            AND (state = 'DRAFT' OR length(trim(coalesce(cancellation_reason, ''))) > 0)
            AND (state = 'CANCELLED_DRAFT' OR cancellation_reason IS NULL)
        )");
        Schema::table('quotation_revisions', function (Blueprint $t): void {
            $t->dropForeign('quotation_revisions_issued_by_fk');
            $t->dropColumn(['official_number', 'numbering_allocation_id', 'issued_at', 'issued_by', 'issued_timezone', 'expires_at']);
        });
    }
};
