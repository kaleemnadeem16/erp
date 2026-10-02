<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_package_definition() RETURNS trigger AS $$
DECLARE inner_item bigint; inner_quantity numeric;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Archive package definitions' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'UPDATE' AND ROW(NEW.company_id,NEW.item_id,NEW.code,NEW.stock_quantity,NEW.quantity_precision,NEW.inner_packaging_id,NEW.inner_count)
  IS DISTINCT FROM ROW(OLD.company_id,OLD.item_id,OLD.code,OLD.stock_quantity,OLD.quantity_precision,OLD.inner_packaging_id,OLD.inner_count) THEN
  RAISE EXCEPTION 'Package identity/content is immutable' USING ERRCODE = '23514';
 END IF;
 IF NEW.inner_packaging_id IS NOT NULL THEN
  SELECT item_id,stock_quantity INTO inner_item,inner_quantity FROM item_packagings WHERE company_id=NEW.company_id AND id=NEW.inner_packaging_id;
  IF inner_item IS DISTINCT FROM NEW.item_id OR inner_quantity * NEW.inner_count <> NEW.stock_quantity THEN
   RAISE EXCEPTION 'Inner package must match item and direct quantity' USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER package_definition_guard BEFORE INSERT OR UPDATE OR DELETE ON item_packagings FOR EACH ROW EXECUTE FUNCTION guard_package_definition();
CREATE OR REPLACE FUNCTION guard_package_stock_unit() RETURNS trigger AS $$
BEGIN
 IF NEW.stock_uom_id IS DISTINCT FROM OLD.stock_uom_id AND EXISTS(SELECT 1 FROM item_packagings WHERE company_id=OLD.company_id AND item_id=OLD.id) THEN
  RAISE EXCEPTION 'Stock unit cannot change after package definitions exist' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER package_stock_unit_guard BEFORE UPDATE ON items FOR EACH ROW EXECUTE FUNCTION guard_package_stock_unit();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS package_stock_unit_guard ON items');
        DB::statement('DROP FUNCTION IF EXISTS guard_package_stock_unit()');
        DB::statement('DROP TRIGGER IF EXISTS package_definition_guard ON item_packagings');
        DB::statement('DROP FUNCTION IF EXISTS guard_package_definition()');
    }
};
