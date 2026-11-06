<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company branding (issue #23, ADR 0162): the company's presentation identity for its documents, kept as immutable, numbered, company-scoped versions.
 *
 *  - A version holds what a printed document may show beside the company's own profile: an optional display name, an optional tagline and an optional logo. The logo is a
 *    reference to a VERIFIED Files blob (never bytes in this table), pinned with its hash and measured size so a later reader can prove it got the same file.
 *  - Versions are never edited or deleted (the database refuses both, and TRUNCATE): a change is a new version with the reason it was made and who made it. The current brand is
 *    the highest version; a version with nothing set is how a brand is cleared. The version number is assigned by the Action under a company lock; the unique key refuses a duplicate.
 *  - Tenancy is by composite keys, including the blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_brand_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedInteger('version');
            $t->string('display_name', 120)->nullable();
            $t->string('tagline', 160)->nullable();
            $t->unsignedBigInteger('logo_blob_id')->nullable();
            $t->char('logo_sha256', 64)->nullable();
            $t->string('logo_media_type', 30)->nullable();
            $t->unsignedInteger('logo_width')->nullable();
            $t->unsignedInteger('logo_height')->nullable();
            $t->unsignedInteger('logo_size_bytes')->nullable();
            $t->string('reason', 1000);
            $t->unsignedBigInteger('created_by');
            $t->timestampTz('created_at', 6);
            $t->unique(['company_id', 'id'], 'company_brand_versions_company_id_unique');
            $t->unique(['company_id', 'version'], 'company_brand_versions_version_unique');
            $t->foreign(['company_id', 'logo_blob_id'], 'company_brand_versions_blob_fk')->references(['company_id', 'id'])->on('file_blobs');
            $t->foreign(['company_id', 'created_by'], 'company_brand_versions_creator_fk')->references(['company_id', 'id'])->on('users');
            $t->index(['company_id', 'logo_blob_id'], 'company_brand_versions_by_blob');
        });
        DB::statement("ALTER TABLE company_brand_versions ADD CONSTRAINT company_brand_versions_shape CHECK (
            version >= 1 AND length(trim(reason)) > 0
            AND (display_name IS NULL OR length(trim(display_name)) > 0) AND (tagline IS NULL OR length(trim(tagline)) > 0)
            AND ((logo_blob_id IS NULL AND logo_sha256 IS NULL AND logo_media_type IS NULL AND logo_width IS NULL AND logo_height IS NULL AND logo_size_bytes IS NULL)
              OR (logo_blob_id IS NOT NULL AND logo_sha256 ~ '^[0-9a-f]{64}\$' AND logo_media_type IN ('image/png','image/jpeg') AND logo_width >= 1 AND logo_height >= 1 AND logo_size_bytes >= 1))
        )");

        // A brand change is also recorded in the company's change history, under its own section.
        DB::statement('ALTER TABLE company_changes DROP CONSTRAINT company_changes_section_check');
        DB::statement("ALTER TABLE company_changes ADD CONSTRAINT company_changes_section_check CHECK (section IN ('GENERAL', 'LOCALIZATION', 'REGISTRATION', 'CONTACT', 'BRANDING'))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION company_brand_versions_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  NEW.created_at := clock_timestamp();
  RETURN NEW;
 END IF;
 RAISE EXCEPTION 'company_brand_versions rows are history and cannot be changed or removed' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER company_brand_versions_clock BEFORE INSERT ON company_brand_versions FOR EACH ROW EXECUTE FUNCTION company_brand_versions_guard();
CREATE TRIGGER company_brand_versions_guard BEFORE UPDATE OR DELETE ON company_brand_versions FOR EACH ROW EXECUTE FUNCTION company_brand_versions_guard();
CREATE TRIGGER company_brand_versions_truncate_guard BEFORE TRUNCATE ON company_brand_versions FOR EACH STATEMENT EXECUTE FUNCTION company_brand_versions_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::table('company_changes')->where('section', 'BRANDING')->exists()) {
            throw new RuntimeException('Company change history that records branding exists; it is history and cannot be rolled back.');
        }
        DB::statement('ALTER TABLE company_changes DROP CONSTRAINT company_changes_section_check');
        DB::statement("ALTER TABLE company_changes ADD CONSTRAINT company_changes_section_check CHECK (section IN ('GENERAL', 'LOCALIZATION', 'REGISTRATION', 'CONTACT'))");
        // History is append-only: dropping the table is the one deliberate removal, and only the migration may do it.
        DB::unprepared('DROP TRIGGER IF EXISTS company_brand_versions_truncate_guard ON company_brand_versions');
        DB::unprepared('DROP TRIGGER IF EXISTS company_brand_versions_guard ON company_brand_versions');
        DB::unprepared('DROP TRIGGER IF EXISTS company_brand_versions_clock ON company_brand_versions');
        Schema::dropIfExists('company_brand_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS company_brand_versions_guard()');
    }
};
