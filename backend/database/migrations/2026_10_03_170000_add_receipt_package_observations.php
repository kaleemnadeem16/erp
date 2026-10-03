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
        Schema::create('goods_receipt_package_observations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('goods_receipt_line_id')->constrained();
            $t->foreignId('item_packaging_id')->nullable()->constrained('item_packagings');
            $t->string('form', 20);
            $t->unsignedInteger('sealed_count')->nullable();
            $t->decimal('content_quantity', 19, 4)->nullable();
            $t->decimal('stock_quantity', 19, 4);
            $t->foreignId('observed_by')->constrained('users');
            $t->text('reason');
            $t->timestampTz('observed_at');
        });
        DB::statement("ALTER TABLE goods_receipt_package_observations ADD CHECK (stock_quantity > 0 AND length(trim(reason)) > 0 AND ((form='SEALED' AND item_packaging_id IS NOT NULL AND sealed_count IS NOT NULL AND sealed_count > 0 AND content_quantity IS NOT NULL AND content_quantity > 0 AND stock_quantity=sealed_count*content_quantity) OR (form='OPEN_LOOSE' AND item_packaging_id IS NULL AND sealed_count IS NULL AND content_quantity IS NULL)))");
        Schema::table('inventory_package_operations', function (Blueprint $t): void {
            $t->foreignId('source_receipt_line_id')->nullable()->constrained('goods_receipt_lines');
        });
        DB::statement('CREATE UNIQUE INDEX package_receipt_source ON inventory_package_operations(company_id,source_receipt_line_id) WHERE source_receipt_line_id IS NOT NULL');
        DB::statement("ALTER TABLE inventory_package_operations ADD CHECK ((evidence_kind='COUNTED' AND source_receipt_line_id IS NULL) OR (evidence_kind='RECEIPT' AND source_receipt_line_id IS NOT NULL))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_receipt_package_observation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE line goods_receipt_lines; receipt goods_receipts; row_data goods_receipt_package_observations;
BEGIN
 IF TG_OP='DELETE' THEN row_data=OLD; ELSE row_data=NEW; END IF;
 IF TG_OP='UPDATE' AND NEW.goods_receipt_line_id<>OLD.goods_receipt_line_id THEN
  RAISE EXCEPTION 'Receipt observations cannot be reparented';
 END IF;
 SELECT * INTO STRICT line FROM goods_receipt_lines WHERE id=row_data.goods_receipt_line_id;
 SELECT * INTO STRICT receipt FROM goods_receipts WHERE id=line.goods_receipt_id;
 IF receipt.status<>'DRAFT' THEN RAISE EXCEPTION 'Posted receipt observations are immutable'; END IF;
 IF TG_OP<>'DELETE' THEN
  IF NOT EXISTS (SELECT 1 FROM users WHERE id=row_data.observed_by AND company_id=receipt.company_id) THEN
   RAISE EXCEPTION 'Observation actor belongs to another company';
  END IF;
  IF row_data.item_packaging_id IS NOT NULL AND NOT EXISTS (
   SELECT 1 FROM item_packagings WHERE id=row_data.item_packaging_id AND company_id=receipt.company_id AND item_id=line.item_id AND stock_quantity=row_data.content_quantity
  ) THEN RAISE EXCEPTION 'Observed package does not match receipt item and contents'; END IF;
 END IF;
 RETURN row_data;
END $$;
CREATE TRIGGER receipt_observation_scope BEFORE INSERT OR UPDATE OR DELETE ON goods_receipt_package_observations
FOR EACH ROW EXECUTE FUNCTION guard_receipt_package_observation();
CREATE OR REPLACE FUNCTION guard_package_receipt_source() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.source_receipt_line_id IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM goods_receipt_lines l JOIN goods_receipts r ON r.id=l.goods_receipt_id
  WHERE l.id=NEW.source_receipt_line_id AND r.company_id=NEW.company_id AND l.item_id=NEW.item_id AND l.location_id=NEW.location_id
 ) THEN RAISE EXCEPTION 'Package receipt source does not match operation scope'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER package_receipt_scope BEFORE INSERT ON inventory_package_operations FOR EACH ROW EXECUTE FUNCTION guard_package_receipt_source();
CREATE OR REPLACE FUNCTION require_posted_package_receipt() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.source_receipt_line_id IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM goods_receipt_lines l JOIN goods_receipts r ON r.id=l.goods_receipt_id
  WHERE l.id=NEW.source_receipt_line_id AND r.status IN ('POSTED','REVERSED')
 ) THEN RAISE EXCEPTION 'Receipt package evidence requires posted receipt'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER package_receipt_posted AFTER INSERT ON inventory_package_operations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_posted_package_receipt();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS package_receipt_scope ON inventory_package_operations');
        DB::statement('DROP TRIGGER IF EXISTS package_receipt_posted ON inventory_package_operations');
        DB::statement('DROP INDEX IF EXISTS package_receipt_source');
        // Drop the source check before removing its column.
        Schema::table('inventory_package_operations', fn (Blueprint $t) => $t->dropConstrainedForeignId('source_receipt_line_id'));
        Schema::dropIfExists('goods_receipt_package_observations');
        DB::statement('DROP FUNCTION IF EXISTS guard_receipt_package_observation(), guard_package_receipt_source(), require_posted_package_receipt()');
    }
};
