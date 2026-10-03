<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->string('default_package_tracking_mode', 30)->default('QUANTITY_ONLY'));
        Schema::table('categories', fn (Blueprint $t) => $t->string('default_package_tracking_mode', 30)->nullable());
        Schema::table('items', function (Blueprint $t): void {
            $t->string('package_tracking_mode', 30)->default('QUANTITY_ONLY');
            // Existing/raw-created items are known quantity-only; no inherited provenance is invented.
            $t->string('package_tracking_source', 35)->default('UNATTRIBUTED_QUANTITY_ONLY');
            $t->unsignedBigInteger('package_tracking_source_category_id')->nullable();
            $t->foreign(['company_id', 'package_tracking_source_category_id'])->references(['company_id', 'id'])->on('categories');
        });
        DB::statement("ALTER TABLE companies ADD CONSTRAINT company_package_mode_gate CHECK (default_package_tracking_mode = 'QUANTITY_ONLY')");
        DB::statement("ALTER TABLE categories ADD CONSTRAINT category_package_mode_gate CHECK (default_package_tracking_mode IS NULL OR default_package_tracking_mode = 'QUANTITY_ONLY')");
        DB::statement("ALTER TABLE items ADD CONSTRAINT item_package_mode_gate CHECK (package_tracking_mode = 'QUANTITY_ONLY')");
        DB::statement("ALTER TABLE items ADD CONSTRAINT item_package_policy_source CHECK ((package_tracking_source = 'CATEGORY' AND package_tracking_source_category_id IS NOT NULL) OR (package_tracking_source IN ('ITEM','COMPANY','UNATTRIBUTED_QUANTITY_ONLY') AND package_tracking_source_category_id IS NULL))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION freeze_item_package_policy() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.package_tracking_mode IS DISTINCT FROM OLD.package_tracking_mode
 OR NEW.package_tracking_source IS DISTINCT FROM OLD.package_tracking_source
 OR NEW.package_tracking_source_category_id IS DISTINCT FROM OLD.package_tracking_source_category_id THEN
  RAISE EXCEPTION 'Item package policy is pinned; governed transitions are not implemented';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER item_package_policy_pinned BEFORE UPDATE ON items FOR EACH ROW EXECUTE FUNCTION freeze_item_package_policy();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS item_package_policy_pinned ON items');
        DB::statement('DROP FUNCTION IF EXISTS freeze_item_package_policy()');
        DB::statement('ALTER TABLE items DROP CONSTRAINT item_package_mode_gate, DROP CONSTRAINT item_package_policy_source');
        DB::statement('ALTER TABLE categories DROP CONSTRAINT category_package_mode_gate');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT company_package_mode_gate');
        Schema::table('items', function (Blueprint $t): void {
            $t->dropForeign(['company_id', 'package_tracking_source_category_id']);
            $t->dropColumn(['package_tracking_mode', 'package_tracking_source', 'package_tracking_source_category_id']);
        });
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('default_package_tracking_mode'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('default_package_tracking_mode'));
    }
};
