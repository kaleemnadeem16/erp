<?php

namespace Tests\Feature\Modules\Inventory\Concerns;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RequestStockRecalculation;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;

trait BuildsOutboundRecalculation
{
    private Item $item;

    private Uom $uom;

    private User $requester;

    private function fixture(ValuationMethod $method, AvcoPoolScope $scope = AvcoPoolScope::CompanyItem, ?string $tracking = null): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['inventory.recalculation.request', 'inventory.recalculation.approve', 'inventory.recalculation.approve-high-risk'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update([
            'base_currency_id' => $currency->id, 'authorised_forward_recalculation_enabled' => true,
            'backdating_horizon_mode' => 'POSTING_FLOOR', 'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2020-01-01',
        ]);
        $this->uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => 'COUNT', 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => $method->value]);
        $account = ChartOfAccount::firstOrCreate(['code' => 'inv'], ['name' => 'Inventory', 'type' => 'ASSET']);
        $this->item = Item::create([
            'code' => 'item', 'name' => 'Item', 'type' => 'RAW', 'stock_uom_id' => $this->uom->id, 'category_id' => $category->id,
            'is_stocked' => true, 'valuation_method' => $method, 'tracking' => $tracking ?? ($method === ValuationMethod::Specific ? 'SERIAL' : ($scope === AvcoPoolScope::CompanyItemBatch ? 'BATCH' : 'NONE')),
            'avco_pool_scope' => $method === ValuationMethod::Avco ? $scope : null, 'inventory_account_id' => $account->id,
        ]);
        $this->requester = User::factory()->create();
        $this->requester->givePermissionTo('inventory.recalculation.request');
    }

    private function location(string $code): Location
    {
        return app(CreateWarehouse::class)(['code' => $code, 'name' => $code, 'location_code' => $code, 'location_name' => $code], PurchasingActor::make(['inventory.warehouse.manage']))->locations()->sole();
    }

    private function receive(Location $location, string $qty, string $cost, string $date, int $id, ?string $serial = null, ?string $batch = null): void
    {
        app(PostInventoryMovement::class)([
            'item_id' => $this->item->id, 'location_id' => $location->id, 'qty' => $qty, 'uom_id' => $this->uom->id,
            'unit_cost' => $cost, 'base_unit_cost' => $cost, 'currency_id' => Company::findOrFail(1)->base_currency_id, 'fx_rate' => '1',
            'source_type' => 'TEST_RECEIPT', 'source_id' => $id, 'moved_at' => Carbon::parse($date), 'serial_no' => $serial, 'batch_no' => $batch,
        ]);
    }

    private function issue(Location $location, string $qty, string $date, int $id, ?string $serial = null, ?string $batch = null): void
    {
        app(PostInventoryMovement::class)->issue([
            'item_id' => $this->item->id, 'location_id' => $location->id, 'qty' => $qty, 'uom_id' => $this->uom->id,
            'source_type' => 'TEST_ISSUE', 'source_id' => $id, 'moved_at' => Carbon::parse($date), 'serial_no' => $serial, 'batch_no' => $batch,
        ]);
    }

    private function transfer(Location $from, Location $to, string $qty, string $date, int $id, ?string $serial = null, ?string $batch = null): void
    {
        app(PostInventoryMovement::class)->transfer([
            'item_id' => $this->item->id, 'from_location_id' => $from->id, 'to_location_id' => $to->id, 'qty' => $qty, 'uom_id' => $this->uom->id,
            'source_type' => 'TEST_TRANSFER', 'source_id' => $id, 'moved_at' => Carbon::parse($date), 'serial_no' => $serial, 'batch_no' => $batch,
        ]);
    }

    private function request(Location $from, string $qty, string $date, ?Location $to = null, ?string $serial = null, ?string $batch = null): RecalculationRequest
    {
        return app(RequestStockRecalculation::class)([
            'movement_kind' => $to === null ? 'ISSUE' : 'TRANSFER', 'item_id' => $this->item->id,
            'location_id' => $from->id, 'destination_location_id' => $to?->id, 'qty' => $qty,
            'source_type' => 'TEST_PROPOSED', 'source_id' => 100, 'moved_at' => Carbon::parse($date), 'serial_no' => $serial, 'batch_no' => $batch,
        ], $this->requester);
    }
}
