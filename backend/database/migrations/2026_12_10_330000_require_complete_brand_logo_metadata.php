<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database integrity (issue #99): the brand-version shape check judged the logo's hash, type and size with comparisons that are UNKNOWN (and so pass) when the value is NULL. A
 * version could therefore name a logo blob with no hash or no measured size. This adds the missing rule: a logo reference is all-or-nothing, every pinned detail present together
 * with the blob id. The earlier check stays and still validates the values themselves.
 *
 * Existing rows: added NOT VALID, validated only if every existing row already complies; otherwise it still guards new rows and the count is logged (versions are history and are
 * never rewritten).
 */
return new class extends Migration
{
    private const RULE = '(logo_blob_id IS NULL AND logo_sha256 IS NULL AND logo_media_type IS NULL AND logo_width IS NULL AND logo_height IS NULL AND logo_size_bytes IS NULL)
        OR (logo_blob_id IS NOT NULL AND logo_sha256 IS NOT NULL AND logo_media_type IS NOT NULL AND logo_width IS NOT NULL AND logo_height IS NOT NULL AND logo_size_bytes IS NOT NULL)';

    public function up(): void
    {
        DB::statement('ALTER TABLE company_brand_versions ADD CONSTRAINT company_brand_versions_logo_complete CHECK ('.self::RULE.') NOT VALID');
        $bad = (int) DB::selectOne('SELECT count(*) AS n FROM company_brand_versions WHERE NOT ('.self::RULE.')')->n;
        if ($bad === 0) {
            DB::statement('ALTER TABLE company_brand_versions VALIDATE CONSTRAINT company_brand_versions_logo_complete');
        } else {
            Log::warning("company_brand_versions: {$bad} existing version(s) name a logo with incomplete pinned details; the rule guards new rows only. Review them.");
        }
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE company_brand_versions IN ACCESS EXCLUSIVE MODE');
        DB::statement('ALTER TABLE company_brand_versions DROP CONSTRAINT company_brand_versions_logo_complete');
    }
};
