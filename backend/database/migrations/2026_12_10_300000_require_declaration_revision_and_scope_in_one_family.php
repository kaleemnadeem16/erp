<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database integrity (issue #99): a seller's tax declaration names a revision AND a logical scope, and the two used to be tied to the company only. Nothing stopped a row from
 * pairing a revision of one quotation family with a scope of another. The row now carries its family and both references are composite (company, family, id) keys, exactly like
 * the draft lines. The family is derived from the revision by the existing insert guard, so callers do not change.
 *
 * Existing rows: the family is back-filled from the row's own revision (the table is append-only, so the guard is switched off for that one statement while this migration holds the
 * table lock). The revision key can always be validated; the scope key is validated only if every existing row already agrees, otherwise it stays enforced for new rows and the
 * disagreeing rows are logged for review (evidence is never rewritten here).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE quotation_draft_tax_declarations IN ACCESS EXCLUSIVE MODE');
        DB::statement('ALTER TABLE quotation_draft_tax_declarations ADD COLUMN family_id bigint NULL');
        DB::statement('ALTER TABLE quotation_draft_tax_declarations DISABLE TRIGGER quotation_tax_declaration_append_only');
        DB::statement('UPDATE quotation_draft_tax_declarations d SET family_id = r.family_id FROM quotation_revisions r WHERE r.company_id = d.company_id AND r.id = d.revision_id');
        DB::statement('ALTER TABLE quotation_draft_tax_declarations ENABLE TRIGGER quotation_tax_declaration_append_only');
        DB::statement('ALTER TABLE quotation_draft_tax_declarations ALTER COLUMN family_id SET NOT NULL');

        DB::statement('ALTER TABLE quotation_draft_tax_declarations ADD CONSTRAINT quotation_tax_declarations_family_revision_fk FOREIGN KEY (company_id, family_id, revision_id) REFERENCES quotation_revisions (company_id, family_id, id) NOT VALID');
        DB::statement('ALTER TABLE quotation_draft_tax_declarations ADD CONSTRAINT quotation_tax_declarations_family_scope_fk FOREIGN KEY (company_id, family_id, logical_scope_id) REFERENCES quotation_logical_scopes (company_id, family_id, id) NOT VALID');
        DB::statement('ALTER TABLE quotation_draft_tax_declarations VALIDATE CONSTRAINT quotation_tax_declarations_family_revision_fk');
        $strays = DB::selectOne('SELECT count(*) AS n FROM quotation_draft_tax_declarations d WHERE NOT EXISTS (SELECT 1 FROM quotation_logical_scopes s WHERE s.company_id = d.company_id AND s.family_id = d.family_id AND s.id = d.logical_scope_id)')->n;
        if ((int) $strays === 0) {
            DB::statement('ALTER TABLE quotation_draft_tax_declarations VALIDATE CONSTRAINT quotation_tax_declarations_family_scope_fk');
        } else {
            Log::warning("quotation_draft_tax_declarations: {$strays} existing declaration(s) name a scope outside their revision's family; the scope key is enforced for new rows only. Review them.");
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quotation_tax_declaration_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rev_state text; rev_family bigint; latest integer;
BEGIN
 SELECT state, family_id INTO rev_state, rev_family FROM quotation_revisions WHERE company_id = NEW.company_id AND id = NEW.revision_id FOR UPDATE;
 IF rev_state IS DISTINCT FROM 'DRAFT' THEN RAISE EXCEPTION 'A tax declaration can only be made on a draft quotation' USING ERRCODE = '23514'; END IF;
 IF NEW.family_id IS NULL THEN NEW.family_id := rev_family; END IF;
 SELECT COALESCE(max(version_no), 0) INTO latest FROM quotation_draft_tax_declarations WHERE company_id = NEW.company_id AND revision_id = NEW.revision_id AND logical_scope_id = NEW.logical_scope_id;
 IF NEW.version_no <> latest + 1 THEN RAISE EXCEPTION 'A tax declaration is the next version of its line' USING ERRCODE = '23514'; END IF;
 NEW.declared_at := now();
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE quotation_draft_tax_declarations IN ACCESS EXCLUSIVE MODE');
        // The family is derived from the revision, so dropping it loses no evidence.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quotation_tax_declaration_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rev_state text; latest integer;
BEGIN
 SELECT state INTO rev_state FROM quotation_revisions WHERE company_id = NEW.company_id AND id = NEW.revision_id FOR UPDATE;
 IF rev_state IS DISTINCT FROM 'DRAFT' THEN RAISE EXCEPTION 'A tax declaration can only be made on a draft quotation' USING ERRCODE = '23514'; END IF;
 SELECT COALESCE(max(version_no), 0) INTO latest FROM quotation_draft_tax_declarations WHERE company_id = NEW.company_id AND revision_id = NEW.revision_id AND logical_scope_id = NEW.logical_scope_id;
 IF NEW.version_no <> latest + 1 THEN RAISE EXCEPTION 'A tax declaration is the next version of its line' USING ERRCODE = '23514'; END IF;
 NEW.declared_at := now();
 RETURN NEW;
END $$;
SQL);
        DB::statement('ALTER TABLE quotation_draft_tax_declarations DROP CONSTRAINT quotation_tax_declarations_family_scope_fk');
        DB::statement('ALTER TABLE quotation_draft_tax_declarations DROP CONSTRAINT quotation_tax_declarations_family_revision_fk');
        DB::statement('ALTER TABLE quotation_draft_tax_declarations DROP COLUMN family_id');
    }
};
