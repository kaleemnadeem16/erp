<?php

namespace Tests\Feature\Modules\Estimating;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Estimating\Actions\AllocateInventory;
use App\Modules\Estimating\Actions\PreviewInventoryAvailability;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\QueryMaterialCost;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/**
 * C2b: proposals and mixes are priced for the quantity actually drawn, through Inventory's cost query. A draw's cost
 * (SELECTED_DRAW) is separate from the requirement's shortage, a genuine zero is a cost that ranks first, and a balance
 * with no complete evidence ranks last and is never mistaken for the cheapest.
 */
class StockDrawCostTest extends TestCase
{
    use RefreshDatabase;

    private Uom $kg;

    private Category $category;

    /** @var array<string, Location> */
    private array $locations = [];

    private int $sourceId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->category = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'FIFO']);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
    }

    public function test_a_genuine_zero_cost_balance_ranks_cheapest_and_the_draw_is_costed_zero(): void
    {
        $item = $this->item('RESIN');
        $this->receive($item, 'a', '10', '3.0000');
        $this->receive($item, 'b', '10', '0.0000');

        $plan = $this->recommendation($item, '10');

        $this->assertSame($this->locations['b']->id, $plan['lines'][0]['location']['id']);
        $this->assertSame('COSTED', $plan['cost']->status->value);
        $this->assertSame('0.0000', $plan['cost']->toArray()['total_cost']);
        $this->assertSame('SELECTED_DRAW', $plan['cost']->amountBasis);
        $this->assertSame(2, AllocateInventory::RULES_VERSION);
    }

    public function test_the_draw_is_costed_for_what_is_drawn_while_the_shortage_is_reported_separately(): void
    {
        $item = $this->item('RESIN');
        $this->receive($item, 'a', '6', '2.0000');

        $plan = $this->recommendation($item, '10');

        $this->assertSame('6.0000', $plan['covered']);
        $this->assertSame('4.0000', $plan['shortage']);
        $this->assertSame('COSTED', $plan['cost']->status->value);
        $this->assertSame('12.0000', $plan['cost']->toArray()['total_cost']);
    }

    public function test_a_balance_without_complete_evidence_is_not_cheapest_and_makes_the_draw_incomplete(): void
    {
        $item = $this->item('SPARE', ValuationMethod::Avco, AvcoPoolScope::WarehouseItem);
        $this->receive($item, 'a', '5', '4.0000');
        $this->receive($item, 'b', '5', '1.0000');
        AvcoPool::query()->where('item_id', $item->id)->whereHas('warehouse', fn ($q) => $q->where('code', 'b'))
            ->update(['status' => 'FROZEN']);

        $plan = $this->recommendation($item, '10');

        // The frozen pool at b has no usable evidence: a ranks first although b's last receipt was cheaper.
        $this->assertSame($this->locations['a']->id, $plan['lines'][0]['location']['id']);
        $this->assertSame('PARTIAL', $plan['cost']->status->value);
        $this->assertNull($plan['cost']->totalCost);
        $this->assertSame('20.0000', $plan['cost']->toArray()['known_cost']);
        $this->assertNotEmpty(array_filter($plan['limitations'], fn (string $text): bool => str_contains($text, 'cheapest only among')));
    }

    /** @return array<string, mixed> */
    private function recommendation(Item $item, string $required): array
    {
        $leaf = [['item' => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name], 'standard_quantity' => $required, 'uom' => ['id' => $this->kg->id, 'code' => 'kg', 'name' => 'Kilogram']]];
        $result = app(QueryMaterialCost::class)->scenario(
            fn (CostReadSession $session): array => app(PreviewInventoryAvailability::class)($session, $leaf, [], null, true),
        );
        $block = $result['data']['items'][0]['recommendation'];
        $cost = $block['cost']->resource;

        return [
            'lines' => $block['lines'], 'covered' => $block['covered_quantity'], 'shortage' => $block['shortage_quantity'],
            'cost' => $cost, 'limitations' => array_merge($block['limitations'], array_column($block['criteria'], 'note')),
        ];
    }

    private function item(string $code, ValuationMethod $method = ValuationMethod::Fifo, ?AvcoPoolScope $scope = null): Item
    {
        return Item::create([
            'code' => $code, 'name' => $code, 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $this->category->id,
            'is_stocked' => true, 'valuation_method' => $method, 'avco_pool_scope' => $method === ValuationMethod::Avco ? $scope : null,
            'inventory_account_id' => ChartOfAccount::firstOrCreate(['code' => 'inv'], ['name' => 'Inventory', 'type' => AccountType::Asset])->id,
        ]);
    }

    private function receive(Item $item, string $warehouse, string $qty, string $unitCost): void
    {
        $this->locations[$warehouse] ??= app(CreateWarehouse::class)([
            'code' => $warehouse, 'name' => strtoupper($warehouse), 'location_code' => 'main', 'location_name' => 'Main',
        ], PurchasingActor::make(['inventory.warehouse.manage']))->locations()->sole();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $this->locations[$warehouse]->id, 'qty' => $qty, 'uom_id' => $this->kg->id,
            'unit_cost' => $unitCost, 'base_unit_cost' => $unitCost, 'currency_id' => Company::findOrFail(1)->base_currency_id, 'fx_rate' => '1',
            'source_type' => 'TEST_RECEIPT', 'source_id' => ++$this->sourceId, 'moved_at' => now(),
        ]);
    }
}
