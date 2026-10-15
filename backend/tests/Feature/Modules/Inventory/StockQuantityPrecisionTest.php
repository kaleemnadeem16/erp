<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\ItemQuantityUnit;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/** PX2 (ADR 0142): increments, conversions, packages and posted quantities are exact at the four-place ledger or refused with a stable code. */
class StockQuantityPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private Uom $litre;

    private Category $category;

    private Location $location;

    private User $actor;

    private ChartOfAccount $account;

    private int $sourceId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $this->litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'dimension' => 'VOLUME', 'factor_to_base' => '1', 'is_base' => true]);
        $this->category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->account = ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => 'ASSET']);
        $this->actor = PermissionedActor::grant(User::factory()->create(), ['inventory.item.manage', 'inventory.warehouse.manage']);
        $this->location = app(CreateWarehouse::class)(['code' => 'a', 'name' => 'A', 'location_code' => 'main', 'location_name' => 'Main'], $this->actor)->locations()->sole();
    }

    /** @param array<string, mixed> $extra */
    private function item(string $code, array $extra = []): Item
    {
        return Item::create(array_merge(['code' => $code, 'name' => $code, 'type' => 'RAW', 'stock_uom_id' => $this->litre->id,
            'category_id' => $this->category->id, 'is_stocked' => true, 'valuation_method' => 'AVCO', 'avco_pool_scope' => 'COMPANY_ITEM', 'inventory_account_id' => $this->account->id], $extra));
    }

    private function code(callable $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $refusal) {
            return (string) $refusal->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    private function pack(Item $item, string $code, string $content, ?string $increment = null): ItemPackaging
    {
        return app(MaintainItemPackaging::class)(['item_id' => $item->id, 'code' => $code, 'name' => $code, 'stock_quantity' => $content, 'quantity_precision' => $increment], $this->actor);
    }

    public function test_a_stocked_item_increment_must_be_reachable_by_the_ledger(): void
    {
        $this->assertSame('0.001000000', $this->item('paint', ['quantity_precision' => '0.001'])->quantity_precision);
        $this->assertSame('1.000000000', $this->item('pieces', ['quantity_precision' => '1'])->quantity_precision);
        $this->assertSame('0.000100000', $this->item('finest', ['quantity_precision' => '0.0001'])->quantity_precision);

        $this->assertSame('INCREMENT_NOT_REPRESENTABLE', $this->code(fn () => $this->item('too-fine', ['quantity_precision' => '0.00001'])));
        $this->assertSame('INCREMENT_NOT_REPRESENTABLE', $this->code(fn () => $this->item('nine-places', ['quantity_precision' => '0.000000001'])));
        $this->assertSame('INCREMENT_INVALID', $this->code(fn () => $this->item('zero', ['quantity_precision' => '0'])));
        $this->assertSame('INCREMENT_INVALID', $this->code(fn () => $this->item('negative', ['quantity_precision' => '-1'])));
    }

    public function test_the_api_returns_the_reason_code_not_a_server_error(): void
    {
        $this->actingAs($this->actor)->postJson('/api/v1/items', ['code' => 'api-fine', 'name' => 'Fine', 'type' => 'RAW', 'stock_uom_id' => $this->litre->id,
            'category_id' => $this->category->id, 'is_stocked' => true, 'valuation_method' => 'AVCO', 'avco_pool_scope' => 'COMPANY_ITEM', 'quantity_precision' => '0.00001'])
            ->assertUnprocessable()->assertJsonPath('reason_code', 'INCREMENT_NOT_REPRESENTABLE')->assertJsonValidationErrors('quantity_precision');
    }

    public function test_a_non_stocked_item_is_judged_by_its_own_context_which_is_four_places_today(): void
    {
        $service = $this->item('survey', ['type' => 'SERVICE', 'is_stocked' => false, 'valuation_method' => null, 'avco_pool_scope' => null, 'quantity_precision' => '0.25']);
        $this->assertSame('0.250000000', $service->quantity_precision);
        $this->assertSame('INCREMENT_NOT_REPRESENTABLE', $this->code(fn () => $this->item('survey2', ['type' => 'SERVICE', 'is_stocked' => false, 'valuation_method' => null, 'avco_pool_scope' => null, 'quantity_precision' => '0.00001'])));
    }

    public function test_pack_unit_conversions_keep_nine_place_factors_as_evidence_and_check_increments_through_them(): void
    {
        $item = $this->item('thinner');
        $drum = Uom::create(['code' => 'drum', 'name' => 'Drum', 'dimension' => 'COUNT', 'factor_to_base' => '1']);
        // A repeating-looking factor is stored as evidence; no quantity is converted here.
        $unit = ItemQuantityUnit::create(['item_id' => $item->id, 'uom_id' => $drum->id, 'factor' => '6.666666667']);
        $this->assertSame('6.666666667', $unit->factor);

        $can = Uom::create(['code' => 'can', 'name' => 'Can', 'dimension' => 'COUNT', 'factor_to_base' => '1']);
        $ok = ItemQuantityUnit::create(['item_id' => $item->id, 'uom_id' => $can->id, 'factor' => '5', 'quantity_precision' => '0.5']);
        $this->assertSame('0.500000000', $ok->quantity_precision);

        $tin = Uom::create(['code' => 'tin', 'name' => 'Tin', 'dimension' => 'COUNT', 'factor_to_base' => '1']);
        // 0.0001 tin × 0.3 L = 0.00003 L: below the ledger.
        $this->assertSame('INCREMENT_NOT_REPRESENTABLE', $this->code(fn () => ItemQuantityUnit::create(['item_id' => $item->id, 'uom_id' => $tin->id, 'factor' => '0.3', 'quantity_precision' => '0.0001'])));
        $this->assertSame('FACTOR_NOT_REPRESENTABLE', $this->code(fn () => ItemQuantityUnit::create(['item_id' => $item->id, 'uom_id' => $tin->id, 'factor' => '0.0000000001'])));
        $this->assertSame('FACTOR_INVALID', $this->code(fn () => ItemQuantityUnit::create(['item_id' => $item->id, 'uom_id' => $tin->id, 'factor' => '0'])));
    }

    public function test_package_content_must_be_exact_and_fit_the_item_increment(): void
    {
        $item = $this->item('paint', ['quantity_precision' => '0.5']);
        $this->assertSame('2.500000000', $this->pack($item, 'tin', '2.5')->stock_quantity);
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->code(fn () => $this->pack($this->item('plain'), 'odd', '0.33333')));
        $this->assertSame('QUANTITY_INCREMENT_VIOLATED', $this->code(fn () => $this->pack($item, 'quarter', '2.25')));
        // A package increment is checked through its content: half of a 3 L tin is 1.5 L, a multiple of 0.5.
        $this->assertSame('0.500000000', $this->pack($item, 'big', '3', '0.5')->quantity_precision);
        $this->assertSame('INCREMENT_INCOMPATIBLE', $this->code(fn () => $this->pack($item, 'big2', '3', '0.25')));
    }

    public function test_an_increment_cannot_be_changed_to_one_that_strands_a_package(): void
    {
        $item = $this->item('paint', ['quantity_precision' => '0.5']);
        $this->pack($item, 'tin', '2.5');
        $this->assertSame('INCREMENT_INCOMPATIBLE', $this->code(fn () => $item->update(['quantity_precision' => '1'])));
        $item->update(['quantity_precision' => '0.25']); // 2.5 is a multiple of 0.25
        $this->assertSame('0.250000000', $item->refresh()->quantity_precision);
    }

    public function test_the_database_refuses_what_the_model_rule_would_refuse(): void
    {
        $item = $this->item('paint');
        $row = ['company_id' => 1, 'item_id' => $item->id, 'code' => 'raw', 'name' => 'raw', 'created_at' => now(), 'updated_at' => now()];
        try {
            DB::transaction(fn () => DB::table('item_packagings')->insert($row + ['stock_quantity' => '0.333330000']));
            $this->fail('A package content of five places must be refused by the database.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('item_packagings_content_stock_scale_check', $error->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('items')->where('id', $item->id)->update(['quantity_precision' => '0.00001']));
            $this->fail('A stocked item increment finer than the ledger must be refused by the database.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('items_stocked_increment_reachable_check', $error->getMessage());
        }
    }

    public function test_posted_quantities_are_refused_not_rounded_by_the_database(): void
    {
        $item = $this->item('paint');
        $receive = fn (string $qty) => app(PostInventoryMovement::class)(['item_id' => $item->id, 'location_id' => $this->location->id,
            'qty' => $qty, 'uom_id' => $this->litre->id, 'unit_cost' => '2.0000', 'base_unit_cost' => '2.0000',
            'currency_id' => Company::findOrFail(1)->base_currency_id, 'fx_rate' => '1', 'source_type' => 'TEST_RECEIPT', 'source_id' => ++$this->sourceId, 'moved_at' => now()]);

        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->code(fn () => $receive('1.00005')));
        $this->assertSame(0, StockMove::query()->count());
        $this->assertSame(0, StockLayer::query()->count());

        $receive('1.275');
        $this->assertSame('1.2750', (string) StockMove::query()->sole()->qty);

        $issue = fn (string $qty) => app(PostInventoryMovement::class)->issue(['item_id' => $item->id, 'location_id' => $this->location->id,
            'qty' => $qty, 'uom_id' => $this->litre->id, 'source_type' => 'TEST_ISSUE', 'source_id' => ++$this->sourceId, 'moved_at' => now()]);
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->code(fn () => $issue('0.00001')));
        $this->assertSame(1, StockMove::query()->count());
        $this->assertSame('1.2750', (string) StockLayer::query()->sole()->qty_remaining);
    }
}
