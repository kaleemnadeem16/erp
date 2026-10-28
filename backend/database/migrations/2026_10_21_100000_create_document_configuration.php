<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, DF1a (document-print-framework slice DF1, ADR 0137): Documents-owned typed rendering policy — template versions, page-policy versions, the
 * Company default per document type and the history of how that default changed. Nothing about brand, assets, storage or manifests: Company owns the brand
 * and those slices own the rest.
 *
 *  - A VERSION is insert-only from birth: the database refuses every update and delete, so a used version can never change. A change is a new version.
 *  - A TEMPLATE version names a curated layout id and typed section/table rules; a PAGE POLICY version carries paper, orientation, margins, header/footer
 *    bands and the layouts it is compatible with. The database checks the physical dimensions against the format; code-owned catalogues and document-type
 *    constraints are checked by the Actions that write here.
 *  - A COMPANY DEFAULT row (type_key '*' means every type) is the active choice. It moves only forward by exactly one generation, and every generation
 *    must have an ACTIVATION event recording the same references, checked at commit, so the projection can never drift from its history.
 *  - Tenancy is by composite keys. A default may only name a template of its own type (the key includes type_key) and a page policy of its own type or '*'
 *    whose compatible layouts include the template's layout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_template_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('type_key', 120);
            $t->string('template_key', 60);
            $t->unsignedInteger('version');
            $t->string('layout_id', 60);
            $t->unsignedInteger('schema_version')->default(1);
            $t->jsonb('permitted_sections');
            $t->jsonb('mandatory_sections');
            $t->boolean('repeat_table_header')->default(true);
            $t->boolean('keep_rows_together')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id'], 'document_template_versions_company_id_unique');
            $t->unique(['company_id', 'type_key', 'id'], 'document_template_versions_type_unique');
            $t->unique(['company_id', 'type_key', 'template_key', 'version'], 'document_template_versions_identity_unique');
            $t->foreign(['company_id', 'created_by'], 'document_template_versions_created_by_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE document_template_versions ADD CONSTRAINT document_template_versions_shape CHECK (
            type_key ~ '^[a-z0-9]+(\\.[a-z0-9-]+)+$' AND template_key ~ '^[a-z][a-z0-9-]{0,59}\$' AND layout_id ~ '^[a-z][a-z0-9-]{0,59}\$'
            AND version >= 1 AND schema_version >= 1
            AND jsonb_typeof(permitted_sections) = 'array' AND jsonb_typeof(mandatory_sections) = 'array' AND mandatory_sections <@ permitted_sections
        )");

        Schema::create('document_page_policy_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('type_key', 120);
            $t->string('policy_key', 60);
            $t->unsignedInteger('version');
            $t->string('format', 8);
            $t->string('orientation', 10);
            $t->decimal('width_mm', 6, 1);
            $t->decimal('height_mm', 6, 1);
            $t->decimal('margin_top_mm', 8, 4);
            $t->decimal('margin_right_mm', 8, 4);
            $t->decimal('margin_bottom_mm', 8, 4);
            $t->decimal('margin_left_mm', 8, 4);
            $t->string('first_header_mode', 24);
            $t->string('continuation_header_mode', 24);
            $t->string('first_footer_mode', 24);
            $t->string('continuation_footer_mode', 24);
            $t->jsonb('compatible_layouts');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id'], 'document_page_policy_versions_company_id_unique');
            $t->unique(['company_id', 'type_key', 'policy_key', 'version'], 'document_page_policy_versions_identity_unique');
            $t->foreign(['company_id', 'created_by'], 'document_page_policy_versions_created_by_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE document_page_policy_versions ADD CONSTRAINT document_page_policy_versions_shape CHECK (
            (type_key = '*' OR type_key ~ '^[a-z0-9]+(\\.[a-z0-9-]+)+$') AND policy_key ~ '^[a-z][a-z0-9-]{0,59}\$' AND version >= 1
            AND ((format = 'A4' AND orientation = 'PORTRAIT' AND width_mm = 210.0 AND height_mm = 297.0)
              OR (format = 'A4' AND orientation = 'LANDSCAPE' AND width_mm = 297.0 AND height_mm = 210.0)
              OR (format = 'LETTER' AND orientation = 'PORTRAIT' AND width_mm = 215.9 AND height_mm = 279.4)
              OR (format = 'LETTER' AND orientation = 'LANDSCAPE' AND width_mm = 279.4 AND height_mm = 215.9))
            AND margin_top_mm BETWEEN 5 AND 50 AND margin_right_mm BETWEEN 5 AND 50 AND margin_bottom_mm BETWEEN 5 AND 50 AND margin_left_mm BETWEEN 5 AND 50
            AND margin_left_mm + margin_right_mm < width_mm AND margin_top_mm + margin_bottom_mm < height_mm
            AND first_header_mode IN ('FULL','COMPACT','NONE') AND continuation_header_mode IN ('FULL','COMPACT','NONE')
            AND first_footer_mode IN ('PAGE_NUMBER','PAGE_NUMBER_OF_TOTAL','NONE') AND continuation_footer_mode IN ('PAGE_NUMBER','PAGE_NUMBER_OF_TOTAL','NONE')
            AND jsonb_typeof(compatible_layouts) = 'array' AND jsonb_array_length(compatible_layouts) >= 1
        )");

        Schema::create('document_company_defaults', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            // '*' is the Company-wide default for every document type; a type's own row overrides it.
            $t->string('type_key', 120);
            $t->unsignedInteger('generation');
            $t->unsignedBigInteger('active_template_version_id')->nullable();
            $t->unsignedBigInteger('active_page_policy_version_id')->nullable();
            $t->timestampTz('updated_at');
            $t->unique(['company_id', 'type_key'], 'document_company_defaults_type_unique');
            $t->foreign(['company_id', 'type_key', 'active_template_version_id'], 'document_company_defaults_template_fk')->references(['company_id', 'type_key', 'id'])->on('document_template_versions');
            $t->foreign(['company_id', 'active_page_policy_version_id'], 'document_company_defaults_page_policy_fk')->references(['company_id', 'id'])->on('document_page_policy_versions');
        });
        DB::statement("ALTER TABLE document_company_defaults ADD CONSTRAINT document_company_defaults_shape CHECK (
            (type_key = '*' OR type_key ~ '^[a-z0-9]+(\\.[a-z0-9-]+)+$') AND generation >= 1
            AND (active_template_version_id IS NOT NULL OR active_page_policy_version_id IS NOT NULL)
            AND (type_key <> '*' OR active_template_version_id IS NULL)
        )");

        Schema::create('document_default_activations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('type_key', 120);
            $t->unsignedInteger('generation');
            $t->unsignedBigInteger('template_version_id')->nullable();
            $t->unsignedBigInteger('page_policy_version_id')->nullable();
            $t->unsignedBigInteger('previous_template_version_id')->nullable();
            $t->unsignedBigInteger('previous_page_policy_version_id')->nullable();
            $t->boolean('is_downgrade')->default(false);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->text('reason')->nullable();
            $t->timestampTz('activated_at');
            $t->unique(['company_id', 'type_key', 'generation'], 'document_default_activations_generation_unique');
            $t->foreign(['company_id', 'template_version_id'], 'document_default_activations_template_fk')->references(['company_id', 'id'])->on('document_template_versions');
            $t->foreign(['company_id', 'page_policy_version_id'], 'document_default_activations_page_policy_fk')->references(['company_id', 'id'])->on('document_page_policy_versions');
            $t->foreign(['company_id', 'previous_template_version_id'], 'document_default_activations_prev_template_fk')->references(['company_id', 'id'])->on('document_template_versions');
            $t->foreign(['company_id', 'previous_page_policy_version_id'], 'document_default_activations_prev_page_policy_fk')->references(['company_id', 'id'])->on('document_page_policy_versions');
            $t->foreign(['company_id', 'actor_id'], 'document_default_activations_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE document_default_activations ADD CONSTRAINT document_default_activations_shape CHECK (
            generation >= 1 AND (template_version_id IS NOT NULL OR page_policy_version_id IS NOT NULL)
            AND (NOT is_downgrade OR length(trim(coalesce(reason, ''))) > 0)
            AND (reason IS NULL OR length(trim(reason)) > 0)
        )");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER document_template_versions_guard BEFORE UPDATE OR DELETE ON document_template_versions FOR EACH ROW EXECUTE FUNCTION documents_append_only_guard();
CREATE TRIGGER document_page_policy_versions_guard BEFORE UPDATE OR DELETE ON document_page_policy_versions FOR EACH ROW EXECUTE FUNCTION documents_append_only_guard();
CREATE TRIGGER document_default_activations_guard BEFORE UPDATE OR DELETE ON document_default_activations FOR EACH ROW EXECUTE FUNCTION documents_append_only_guard();

-- A default keeps its company and type, is never deleted, and moves forward by exactly one generation at a time.
CREATE OR REPLACE FUNCTION document_company_default_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'document_company_defaults rows are history and cannot be deleted' USING ERRCODE = '23514'; END IF;
 IF ROW(NEW.company_id, NEW.type_key) IS DISTINCT FROM ROW(OLD.company_id, OLD.type_key) THEN RAISE EXCEPTION 'A Company default keeps its company and type' USING ERRCODE = '23514'; END IF;
 IF NEW.generation <> OLD.generation + 1 THEN RAISE EXCEPTION 'A Company default moves forward by exactly one generation' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER document_company_default_guard BEFORE UPDATE OR DELETE ON document_company_defaults FOR EACH ROW EXECUTE FUNCTION document_company_default_guard();

-- At commit: every default equals the activation event of its generation, and its page policy fits its template and type.
CREATE OR REPLACE FUNCTION document_company_default_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE d record; ev record; tpl record; pol record;
BEGIN
 SELECT * INTO d FROM document_company_defaults WHERE company_id = NEW.company_id AND type_key = NEW.type_key;
 IF NOT FOUND THEN RAISE EXCEPTION 'A default activation needs its Company default row' USING ERRCODE = '23514'; END IF;
 IF TG_TABLE_NAME = 'document_default_activations' AND NEW.generation > d.generation THEN RAISE EXCEPTION 'An activation event cannot be ahead of its Company default' USING ERRCODE = '23514'; END IF;
 SELECT * INTO ev FROM document_default_activations WHERE company_id = d.company_id AND type_key = d.type_key AND generation = d.generation;
 IF NOT FOUND OR ev.template_version_id IS DISTINCT FROM d.active_template_version_id OR ev.page_policy_version_id IS DISTINCT FROM d.active_page_policy_version_id THEN
  RAISE EXCEPTION 'A Company default must equal its activation event for generation %', d.generation USING ERRCODE = '23514';
 END IF;
 IF d.active_page_policy_version_id IS NOT NULL THEN
  SELECT type_key, compatible_layouts INTO pol FROM document_page_policy_versions WHERE id = d.active_page_policy_version_id;
  IF pol.type_key NOT IN (d.type_key, '*') THEN RAISE EXCEPTION 'The page policy belongs to another document type' USING ERRCODE = '23514'; END IF;
  IF d.active_template_version_id IS NOT NULL THEN
   SELECT layout_id INTO tpl FROM document_template_versions WHERE id = d.active_template_version_id;
   IF NOT (pol.compatible_layouts ? tpl.layout_id) THEN RAISE EXCEPTION 'The page policy is not compatible with the template layout' USING ERRCODE = '23514'; END IF;
  END IF;
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER document_company_default_check AFTER INSERT OR UPDATE ON document_company_defaults DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION document_company_default_check();
CREATE CONSTRAINT TRIGGER document_default_activation_check AFTER INSERT ON document_default_activations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION document_company_default_check();
SQL);
    }

    public function down(): void
    {
        // Refuse rather than erase configuration history that manifests may already pin.
        if (Schema::hasTable('document_template_versions') && (DB::table('document_template_versions')->exists() || DB::table('document_page_policy_versions')->exists())) {
            throw new RuntimeException('Document template or page-policy versions exist; rolling this migration back would erase configuration history. Plan a backout first.');
        }
        foreach (['document_default_activation_check' => 'document_default_activations', 'document_company_default_check' => 'document_company_defaults',
            'document_company_default_guard' => 'document_company_defaults', 'document_default_activations_guard' => 'document_default_activations',
            'document_page_policy_versions_guard' => 'document_page_policy_versions', 'document_template_versions_guard' => 'document_template_versions'] as $trigger => $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS document_company_default_check()');
        DB::statement('DROP FUNCTION IF EXISTS document_company_default_guard()');
        Schema::dropIfExists('document_default_activations');
        Schema::dropIfExists('document_company_defaults');
        Schema::dropIfExists('document_page_policy_versions');
        Schema::dropIfExists('document_template_versions');
    }
};
