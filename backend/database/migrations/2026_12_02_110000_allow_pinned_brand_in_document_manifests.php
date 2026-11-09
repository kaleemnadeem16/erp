<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company branding on documents (issue #23, ADR 0162): a manifest can now pin the company's brand at the freeze, exactly as it pins the template and page policy.
 *
 *  - `brand_state` is `NOT_AVAILABLE` (no brand: `brand` is NULL and `asset_manifest` empty, exactly as before) or `PINNED` (`brand` holds the pinned text and version, and
 *    `asset_manifest` lists the pinned logo with its hash, so the renderer can prove it read the same file). Rows frozen before this migration stay NOT_AVAILABLE and untouched.
 *  - The column is new and nullable; manifests stay insert-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_manifests', function ($t): void {
            $t->jsonb('brand')->nullable();
        });
        DB::statement('ALTER TABLE document_manifests DROP CONSTRAINT document_manifests_shape');
        DB::statement(self::shape("(brand_state = 'NOT_AVAILABLE' AND brand IS NULL AND asset_manifest = '[]'::jsonb) OR (brand_state = 'PINNED' AND brand IS NOT NULL AND jsonb_typeof(brand) = 'object' AND octet_length(brand::text) <= 65536)"));
    }

    public function down(): void
    {
        // Lock first, then look: nothing can write evidence between the check and the drop.
        DB::statement('LOCK TABLE document_manifests IN ACCESS EXCLUSIVE MODE');
        if (DB::table('document_manifests')->where('brand_state', 'PINNED')->exists()) {
            throw new RuntimeException('Manifests that pinned a company brand exist; they are evidence and cannot be rolled back to the unbranded shape.');
        }
        DB::statement('ALTER TABLE document_manifests DROP CONSTRAINT document_manifests_shape');
        DB::statement(self::shape("brand_state = 'NOT_AVAILABLE'"));
        Schema::table('document_manifests', function ($t): void {
            $t->dropColumn('brand');
        });
    }

    private static function shape(string $brand): string
    {
        return "ALTER TABLE document_manifests ADD CONSTRAINT document_manifests_shape CHECK (
            purpose IN ('DRAFT_PREVIEW','ORIGINAL') AND length(trim(stable_generation_identity)) > 0 AND schema_version >= 1 AND source_generation >= 1
            AND input_digest ~ '^[0-9a-f]{64}\$' AND pinned_manifest_digest ~ '^[0-9a-f]{64}\$'
            AND (source_snapshot_reference IS NULL OR length(trim(source_snapshot_reference)) > 0)
            AND jsonb_typeof(frozen_payload) = 'object' AND octet_length(frozen_payload::text) <= 1048576
            AND jsonb_typeof(resolved_template) = 'object' AND jsonb_typeof(resolved_page_policy) = 'object'
            AND ({$brand}) AND jsonb_typeof(asset_manifest) = 'array' AND jsonb_typeof(fonts) = 'array' AND jsonb_typeof(locale_formatting) = 'object'
            AND length(trim(renderer_contract)) > 0 AND length(trim(renderer_version)) > 0
        )";
    }
};
