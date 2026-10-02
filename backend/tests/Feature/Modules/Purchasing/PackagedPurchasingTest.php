<?php
namespace Tests\Feature\Modules\Purchasing;
use App\Models\User;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Purchasing\Actions\MaintainSupplierOffer;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Models\AvcoPool;
use Tests\Support\PurchasingActor;
class PackagedPurchasingTest extends PurchaseOrderFoundationTest {
 public function test_equal_content_packages_offer_pricing_partial_receipts_and_immutable_terms(): void {
  [$seed,$item,$uom]=$this->makeOrder();
  $actor=PurchasingActor::make(['inventory.item.manage','purchasing.item-supplier.manage','purchasing.purchase-order.create','purchasing.purchase-order.issue','purchasing.goods-receipt.create','purchasing.goods-receipt.post']);
  $package=app(MaintainItemPackaging::class)(['item_id'=>$item->id,'code'=>'twenty-small','name'=>'Box 20 small','stock_quantity'=>'2','quantity_precision'=>'1'],$actor);
  $other=app(MaintainItemPackaging::class)(['item_id'=>$item->id,'code'=>'two-large','name'=>'Box 2 large','stock_quantity'=>'2','quantity_precision'=>'1'],$actor);
  $this->assertNotSame($package->id,$other->id);
  $rel=ItemSupplier::create(['item_id'=>$item->id,'supplier_account_id'=>$seed->supplier_account_id,'active'=>true]);
  $offer=app(MaintainSupplierOffer::class)(['item_supplier_id'=>$rel->id,'item_packaging_id'=>$package->id,'purchase_uom_id'=>$uom->id,
   'supplier_item_code'=>'supplier-box','unit_price'=>'850','currency_id'=>$seed->currency_id,'price_uom_id'=>$uom->id,'price_quantity'=>'1',
   'minimum_order_qty'=>'5','order_multiple'=>'5'],$actor);
  $attrs=$seed->getAttributes();unset($attrs['id'],$attrs['company_id'],$attrs['created_at'],$attrs['updated_at']);
  $attrs['lines']=[array_merge($this->lineAttributes($seed,$item,$uom),['item_packaging_id'=>$package->id,'supplier_offer_id'=>$offer->id,'ordered_qty'=>'10','unit_price'=>'850','price_uom_id'=>$uom->id,'price_quantity'=>'1'])];
  $order=app(CreatePurchaseOrder::class)($attrs);
  $line=$order->lines->sole();
  $this->assertSame('20.0000',$line->ordered_stock_qty);
  $this->assertSame('17000.0000',$line->line_amount);
  app(IssuePurchaseOrder::class)($order,$actor);
  app(MaintainSupplierOffer::class)(['unit_price'=>'900','supplier_item_code'=>'changed'],$actor,$offer);
  $this->assertSame('850.0000',$line->refresh()->unit_price);
  $this->assertSame('supplier-box',$line->supplier_item_code);
  $item->update(['is_stocked'=>true,'valuation_method'=>'AVCO','tracking'=>'NONE','avco_pool_scope'=>'COMPANY_ITEM','inventory_account_id'=>\App\Modules\Accounting\Models\ChartOfAccount::query()->firstOrFail()->id]);
  $warehouse=app(CreateWarehouse::class)(['code'=>'package-store','name'=>'Store','location_code'=>'store','location_name'=>'Store']);
  foreach(['6','4'] as $qty){
   $id=$this->actingAs($actor)->postJson('/api/v1/goods-receipts',['supplier_account_id'=>$order->supplier_account_id,'physical_arrival_at'=>now()->toIso8601String(),'posting_date'=>now()->format('Y-m-d'),
    'lines'=>[['purchase_order_line_id'=>$line->id,'item_id'=>$item->id,'location_id'=>$warehouse->locations()->sole()->id,'received_uom_id'=>$uom->id,'received_qty'=>$qty,'item_packaging_id'=>$package->id]]])->assertCreated()->json('data.id');
   $this->postJson("/api/v1/goods-receipts/{$id}/post")->assertOk();
  }
  $this->assertSame('20.0000',AvcoPool::query()->sole()->qty_on_hand);
  $this->assertSame('17000.0000',AvcoPool::query()->sole()->inventory_value);
  $this->assertSame('10.0000', (string) GoodsReceiptLine::query()->sum('received_package_qty'));
 }
 public function test_package_http_and_action_authority_and_nested_validation(): void {
  [$order,$item,$uom]=$this->makeOrder();$user=User::factory()->create();
  $this->actingAsUnprivileged($user)->postJson('/api/v1/item-packagings',[])->assertForbidden();
  try {app(MaintainItemPackaging::class)([],$user);$this->fail('Expected denial');} catch(\App\Exceptions\DomainInvariantException){$this->assertTrue(true);}
  try {app(MaintainSupplierOffer::class)([],$user);$this->fail('Expected denial');} catch(\App\Exceptions\DomainInvariantException){$this->assertTrue(true);}
  $actor=PurchasingActor::make(['inventory.item.manage']);
  $inner=app(MaintainItemPackaging::class)(['item_id'=>$item->id,'code'=>'bottle','name'=>'Bottle','stock_quantity'=>'0.1'],$actor);
  $box=app(MaintainItemPackaging::class)(['item_id'=>$item->id,'code'=>'box','name'=>'Box','stock_quantity'=>'2','inner_packaging_id'=>$inner->id,'inner_count'=>20],$actor);
  $this->assertSame('2.000000000',$box->stock_quantity);
  $this->expectException(\App\Exceptions\DomainInvariantException::class);
  app(MaintainItemPackaging::class)(['stock_quantity'=>'3'],$actor,$box);
 }
 public function test_supplier_multiple_requires_reason_but_does_not_change_quantity(): void {
  [$order,$item,$uom]=$this->makeOrder();$actor=PurchasingActor::make(['inventory.item.manage','purchasing.item-supplier.manage','purchasing.purchase-order.create']);
  $package=app(MaintainItemPackaging::class)(['item_id'=>$item->id,'code'=>'box','name'=>'Box','stock_quantity'=>'2','quantity_precision'=>'1'],$actor);
  $rel=ItemSupplier::create(['item_id'=>$item->id,'supplier_account_id'=>$order->supplier_account_id]);
  $offer=app(MaintainSupplierOffer::class)(['item_supplier_id'=>$rel->id,'item_packaging_id'=>$package->id,'purchase_uom_id'=>$uom->id,'unit_price'=>'850','currency_id'=>$order->currency_id,'price_uom_id'=>$uom->id,'price_quantity'=>'1','minimum_order_qty'=>'5','order_multiple'=>'5'],$actor);
  $input=array_merge($this->lineAttributes($order,$item,$uom),['ordered_qty'=>'7','item_packaging_id'=>$package->id,'supplier_offer_id'=>$offer->id,'unit_price'=>'850','price_uom_id'=>$uom->id]);
  try {app(\App\Modules\Purchasing\Actions\BuildPurchaseOrderLineAttributes::class)($order,$input);$this->fail('Expected reason');} catch(\App\Exceptions\DomainInvariantException){$this->assertTrue(true);}
  $input['ordering_exception_reason']='Supplier agreed to seven boxes.';
  $built=app(\App\Modules\Purchasing\Actions\BuildPurchaseOrderLineAttributes::class)($order,$input);
  $this->assertSame('14.0000',$built['ordered_stock_qty']);
  $this->assertSame('11900.0000',$built['line_amount']);
 }
}
