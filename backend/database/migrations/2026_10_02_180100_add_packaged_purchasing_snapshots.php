<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  Schema::table('purchase_orders',function(Blueprint $t){
   $t->unsignedBigInteger('replaces_purchase_order_id')->nullable();
   $t->foreign(['company_id','replaces_purchase_order_id'])->references(['company_id','id'])->on('purchase_orders');
  });
  Schema::table('purchase_order_lines',function(Blueprint $t){
   $t->unsignedBigInteger('item_packaging_id')->nullable();$t->unsignedBigInteger('supplier_offer_id')->nullable();
   $t->string('package_name')->nullable();$t->string('package_code')->nullable();
   $t->decimal('package_increment',19,9)->nullable();$t->string('order_unit_label')->nullable();
   $t->unsignedBigInteger('price_uom_id')->nullable();$t->string('price_unit_label')->nullable();
   $t->decimal('price_quantity',19,4)->nullable();$t->decimal('price_stock_factor',19,9)->nullable();
   $t->decimal('priced_qty',19,9)->nullable();
   $t->decimal('minimum_order_qty',19,4)->nullable();$t->decimal('order_multiple',19,4)->nullable();
   $t->text('ordering_exception_reason')->nullable();$t->jsonb('offer_snapshot')->nullable();
   foreach(['item_packaging_id'=>'item_packagings','supplier_offer_id'=>'supplier_offers','price_uom_id'=>'uoms'] as $f=>$table){
    $t->foreign(['company_id',$f])->references(['company_id','id'])->on($table);
   }
  });
  Schema::table('goods_receipt_lines',function(Blueprint $t){
   $t->foreignId('item_packaging_id')->nullable()->constrained('item_packagings');$t->string('package_name')->nullable();
   $t->decimal('received_package_qty',19,4)->nullable();
   $t->decimal('provisional_amount',19,4)->nullable();
  });
  DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_packaged_po_snapshot() RETURNS trigger AS $$
BEGIN
 IF (SELECT status FROM purchase_orders WHERE id = OLD.purchase_order_id) <> 'DRAFT'
 AND (to_jsonb(NEW) - 'short_closed_stock_qty' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'short_closed_stock_qty' - 'updated_at') THEN
  RAISE EXCEPTION 'Issued purchase-order snapshots are immutable' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER packaged_po_snapshot_guard BEFORE UPDATE ON purchase_order_lines FOR EACH ROW EXECUTE FUNCTION guard_packaged_po_snapshot();
SQL);

 }
 public function down(): void {
  DB::statement('DROP TRIGGER IF EXISTS packaged_po_snapshot_guard ON purchase_order_lines');
  DB::statement('DROP FUNCTION IF EXISTS guard_packaged_po_snapshot()');
  Schema::table('goods_receipt_lines',fn(Blueprint $t)=>$t->dropForeign(['item_packaging_id']));

  Schema::table('goods_receipt_lines',fn(Blueprint $t)=>$t->dropColumn(['item_packaging_id','package_name','received_package_qty','provisional_amount']));
  Schema::table('purchase_order_lines',function(Blueprint $t){
   foreach(['item_packaging_id','supplier_offer_id','price_uom_id'] as $f){$t->dropForeign(['company_id',$f]);}
   $t->dropColumn(['item_packaging_id','supplier_offer_id','package_name','package_code','package_increment','order_unit_label','price_uom_id','price_unit_label','price_quantity','price_stock_factor','priced_qty','minimum_order_qty','order_multiple','ordering_exception_reason','offer_snapshot']);
  });
  Schema::table('purchase_orders',function(Blueprint $t){$t->dropForeign(['company_id','replaces_purchase_order_id']);$t->dropColumn('replaces_purchase_order_id');});
 }
};
