<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('item_packagings',function(Blueprint $t) {
   $t->id();$t->foreignId('company_id')->constrained();$t->unsignedBigInteger('item_id');
   $t->string('code');$t->string('name');$t->decimal('stock_quantity',19,9);$t->decimal('quantity_precision',19,9)->nullable();
   $t->string('barcode')->nullable();$t->unsignedBigInteger('inner_packaging_id')->nullable();$t->unsignedInteger('inner_count')->nullable();
   $t->boolean('active')->default(true);$t->timestamps();$t->unique(['company_id','id']);$t->unique(['company_id','item_id','code']);
   $t->foreign(['company_id','item_id'])->references(['company_id','id'])->on('items');
   $t->foreign(['company_id','inner_packaging_id'])->references(['company_id','id'])->on('item_packagings');
  });
  DB::statement('ALTER TABLE item_packagings ADD CHECK (stock_quantity > 0 AND (quantity_precision IS NULL OR quantity_precision > 0))');
  DB::statement('ALTER TABLE item_packagings ADD CHECK ((inner_packaging_id IS NULL AND inner_count IS NULL) OR (inner_packaging_id IS NOT NULL AND inner_count > 0 AND inner_packaging_id <> id))');
  Schema::create('supplier_offers',function(Blueprint $t) {
   $t->id();$t->foreignId('company_id')->constrained();$t->unsignedBigInteger('item_supplier_id');$t->unsignedBigInteger('item_packaging_id')->nullable();
   $t->unsignedBigInteger('purchase_uom_id');$t->string('supplier_item_code')->nullable();$t->boolean('preferred')->default(false);
   $t->decimal('minimum_order_qty',19,4)->nullable();$t->decimal('order_multiple',19,4)->nullable();$t->unsignedSmallInteger('lead_time_days')->nullable();
   $t->decimal('unit_price',19,4);$t->foreignId('currency_id')->constrained();$t->unsignedBigInteger('price_uom_id')->nullable();
   $t->decimal('price_quantity',19,4)->default('1');$t->date('valid_from')->nullable();$t->date('valid_to')->nullable();
   $t->boolean('active')->default(true);$t->timestamps();$t->unique(['company_id','id']);
   foreach(['item_supplier_id'=>'item_suppliers','item_packaging_id'=>'item_packagings','purchase_uom_id'=>'uoms','price_uom_id'=>'uoms'] as $f=>$table) {
    $t->foreign(['company_id',$f])->references(['company_id','id'])->on($table);
   }
  });
  DB::statement('ALTER TABLE supplier_offers ADD CHECK (unit_price >= 0 AND price_quantity > 0 AND (minimum_order_qty IS NULL OR minimum_order_qty > 0) AND (order_multiple IS NULL OR order_multiple > 0))');
  DB::statement('ALTER TABLE supplier_offers ADD CHECK (item_packaging_id IS NOT NULL OR price_uom_id IS NOT NULL)');
  DB::statement('ALTER TABLE supplier_offers ADD CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_to >= valid_from)');
  DB::statement('CREATE UNIQUE INDEX supplier_offers_preferred ON supplier_offers (company_id,item_supplier_id) WHERE active AND preferred');
 }
 public function down(): void {Schema::dropIfExists('supplier_offers');Schema::dropIfExists('item_packagings');}
};
