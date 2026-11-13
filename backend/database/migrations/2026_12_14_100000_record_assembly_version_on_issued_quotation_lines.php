<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-phase integration (Phase 3 Estimating -> Phase 4 Sales): an issued Assembly line names the exact Assembly version it was offered from, frozen with the rest of
 * the line, so the offer stays historically identifiable after a newer version activates. Issued lines are append-only; the column is written once, at issue.
 *
 * ADDITIVE: one nullable column, its foreign key and shape check, and an insert guard that also requires the frozen version to equal the draft line's. No Assembly line could
 * be drafted before this release (the draft Action refused the kind), so no issued Assembly line exists and nothing needs a backfill; the migration refuses if one does.
 * down() restores the original guard and refuses while an issued Assembly line exists, because dropping the column would erase which version was sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE quotation_issued_lines IN ACCESS EXCLUSIVE MODE');
        if (DB::table('quotation_issued_lines')->where('source_kind', 'ASSEMBLY_WORK')->exists()) {
            throw new RuntimeException('Issued Assembly lines already exist without their version; resolve them before this migration (a separately approved correction).');
        }
        Schema::table('quotation_issued_lines', function (Blueprint $t): void {
            $t->unsignedBigInteger('assembly_version_id')->nullable();
            $t->foreign('assembly_version_id', 'quotation_issued_lines_assembly_version_fk')->references('id')->on('assembly_versions');
        });
        DB::statement("ALTER TABLE quotation_issued_lines ADD CONSTRAINT quotation_issued_lines_assembly_shape CHECK (
            (source_kind = 'ASSEMBLY_WORK') = (assembly_version_id IS NOT NULL) AND (assembly_version_id IS NULL OR (item_id IS NULL AND manual_measure IS NULL))
        )");
        DB::unprepared(self::guard(true));
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE quotation_issued_lines IN ACCESS EXCLUSIVE MODE');
        if (DB::table('quotation_issued_lines')->whereNotNull('assembly_version_id')->exists()) {
            throw new RuntimeException('Issued Assembly lines exist: rolling back would erase which Assembly version was sold. Plan a separately approved backout first.');
        }
        DB::unprepared(self::guard(false));
        DB::statement('ALTER TABLE quotation_issued_lines DROP CONSTRAINT IF EXISTS quotation_issued_lines_assembly_shape');
        Schema::table('quotation_issued_lines', function (Blueprint $t): void {
            $t->dropForeign('quotation_issued_lines_assembly_version_fk');
            $t->dropColumn('assembly_version_id');
        });
    }

    private static function guard(bool $withVersion): string
    {
        $check = $withVersion ? <<<'SQL'
 SELECT d.assembly_version_id INTO draft_version FROM quotation_draft_lines d WHERE d.company_id = NEW.company_id AND d.revision_id = NEW.revision_id AND d.id = NEW.source_line_id;
 IF NEW.assembly_version_id IS DISTINCT FROM draft_version THEN RAISE EXCEPTION 'An issued line freezes the Assembly version of its draft line' USING ERRCODE = '23514'; END IF;
SQL : '';
        $declare = $withVersion ? 'DECLARE rev_state text; draft_version bigint;' : 'DECLARE rev_state text;';

        return <<<SQL
CREATE OR REPLACE FUNCTION quotation_issued_line_insert_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
{$declare}
BEGIN
 SELECT r.state INTO rev_state FROM quotation_revisions r WHERE r.id = NEW.revision_id;
 IF rev_state IS DISTINCT FROM 'DRAFT' THEN RAISE EXCEPTION 'Issued lines are written with their issue, before the revision becomes issued' USING ERRCODE = '23514'; END IF;
{$check}
 RETURN NEW;
END \$\$;
SQL;
    }
};
