<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, FR3: the tax nature and classification a seller declares for a MANUAL line of a DRAFT ("manual -> explicit declared nature", Tax domain). A manual line has no Item whose
 * classification Tax could read, and Sales must not invent one, so the seller states it. It is evidence, not a setting: every declaration is a new immutable VERSION for the line's
 * logical scope in that revision, and a correction is simply the next version with its reason. Earlier versions stay. The latest version is the one in force; whether Tax covers it
 * is Tax's decision (an uncovered classification is UNKNOWN, never zero). Written only while the revision is a DRAFT; each write moves the revision's input generation; UPDATE, DELETE and
 * TRUNCATE are refused. Every reference is a composite company foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_draft_tax_declarations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('revision_id');
            $t->unsignedBigInteger('logical_scope_id');
            $t->unsignedInteger('version_no');
            $t->string('nature', 10);
            $t->string('classification', 80);
            $t->text('reason');
            $t->unsignedBigInteger('declared_by');
            $t->timestampTz('declared_at')->useCurrent();
            $t->unique(['company_id', 'id'], 'quotation_tax_declarations_company_id_unique');
            $t->unique(['company_id', 'revision_id', 'logical_scope_id', 'version_no'], 'quotation_tax_declarations_version_unique');
            $t->foreign(['company_id', 'revision_id'], 'quotation_tax_declarations_revision_fk')->references(['company_id', 'id'])->on('quotation_revisions');
            $t->foreign(['company_id', 'logical_scope_id'], 'quotation_tax_declarations_scope_fk')->references(['company_id', 'id'])->on('quotation_logical_scopes');
            $t->foreign(['company_id', 'declared_by'], 'quotation_tax_declarations_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE quotation_draft_tax_declarations ADD CONSTRAINT quotation_tax_declarations_shape CHECK (
            version_no >= 1 AND nature IN ('GOODS','SERVICES') AND length(trim(classification)) > 0 AND length(trim(reason)) > 0
        )");
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
CREATE TRIGGER quotation_tax_declaration_guard BEFORE INSERT ON quotation_draft_tax_declarations FOR EACH ROW EXECUTE FUNCTION quotation_tax_declaration_guard();

CREATE OR REPLACE FUNCTION quotation_tax_declaration_generation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 UPDATE quotation_revisions SET input_generation = input_generation + 1, updated_at = now() WHERE company_id = NEW.company_id AND id = NEW.revision_id;
 RETURN NULL;
END $$;
CREATE TRIGGER quotation_tax_declaration_generation AFTER INSERT ON quotation_draft_tax_declarations FOR EACH ROW EXECUTE FUNCTION quotation_tax_declaration_generation();

CREATE OR REPLACE FUNCTION quotation_tax_declaration_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Quotation tax declarations are append-only' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER quotation_tax_declaration_append_only BEFORE UPDATE OR DELETE ON quotation_draft_tax_declarations FOR EACH ROW EXECUTE FUNCTION quotation_tax_declaration_append_only();
CREATE TRIGGER quotation_tax_declaration_no_truncate BEFORE TRUNCATE ON quotation_draft_tax_declarations FOR EACH STATEMENT EXECUTE FUNCTION quotation_tax_declaration_append_only();
SQL);
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE quotation_draft_tax_declarations IN ACCESS EXCLUSIVE MODE');
        if (DB::table('quotation_draft_tax_declarations')->exists()) {
            throw new RuntimeException('Quotation tax declarations exist; rolling back would erase evidence.');
        }
        Schema::dropIfExists('quotation_draft_tax_declarations');
        DB::statement('DROP FUNCTION IF EXISTS quotation_tax_declaration_guard()');
        DB::statement('DROP FUNCTION IF EXISTS quotation_tax_declaration_generation()');
        DB::statement('DROP FUNCTION IF EXISTS quotation_tax_declaration_append_only()');
    }
};
