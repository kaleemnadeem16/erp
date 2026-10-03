<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Uom;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\PackageTrackingMode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageTrackingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private Uom $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = Uom::query()->create(['code' => 'ea', 'name' => 'Each', 'dimension' => 'COUNT', 'factor_to_base' => '1']);
    }

    public function test_explicit_item_choice_wins_and_is_persisted(): void
    {
        $category = $this->category(null, 'QUANTITY_ONLY');
        $item = $this->item($category, ['package_tracking_mode' => 'QUANTITY_ONLY']);
        $this->assertSame(PackageTrackingMode::QuantityOnly, $item->package_tracking_mode);
        $this->assertSame('ITEM', $item->package_tracking_source);
        $this->assertNull($item->package_tracking_source_category_id);
    }

    public function test_nearest_category_default_wins_over_more_distant_ancestor(): void
    {
        $root = $this->category(null, 'QUANTITY_ONLY');
        $parent = $this->category($root, 'QUANTITY_ONLY');
        $leaf = $this->category($parent);
        $item = $this->item($leaf);
        $this->assertSame('CATEGORY', $item->package_tracking_source);
        $this->assertSame($parent->id, $item->package_tracking_source_category_id);
    }

    public function test_company_default_is_persisted_when_category_chain_has_no_choice(): void
    {
        $leaf = $this->category($this->category());
        $item = $this->item($leaf);
        $this->assertSame(PackageTrackingMode::QuantityOnly, $item->package_tracking_mode);
        $this->assertSame('COMPANY', $item->package_tracking_source);
        $this->assertNull($item->package_tracking_source_category_id);
    }

    public function test_later_default_changes_do_not_reinterpret_existing_item(): void
    {
        $category = $this->category(null, 'QUANTITY_ONLY');
        $first = $this->item($category);
        $category->update(['default_package_tracking_mode' => null]);
        $second = $this->item($category);
        $this->assertSame('CATEGORY', $first->refresh()->package_tracking_source);
        $this->assertSame('COMPANY', $second->package_tracking_source);
    }

    public function test_moving_item_to_another_category_preserves_its_original_policy_source(): void
    {
        $first = $this->category(null, 'QUANTITY_ONLY');
        $item = $this->item($first);
        $item->update(['category_id' => $this->category()->id]);
        $this->assertSame($first->id, $item->refresh()->package_tracking_source_category_id);
    }

    public function test_advanced_item_choice_cannot_enable_tracking(): void
    {
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('activation is unavailable');
        $this->item($this->category(), ['package_tracking_mode' => 'PACKAGE_BALANCE']);
    }

    public function test_advanced_company_default_cannot_enable_tracking(): void
    {
        $this->expectException(DomainInvariantException::class);
        Company::query()->findOrFail(1)->update(['default_package_tracking_mode' => 'CONTAINER_TRACKED']);
    }

    public function test_advanced_category_default_cannot_enable_tracking(): void
    {
        $this->expectException(DomainInvariantException::class);
        $this->category(null, 'PACKAGE_BALANCE');
    }

    public function test_raw_sql_cannot_insert_an_advanced_item(): void
    {
        $item = $this->item($this->category());
        $attributes = $item->getAttributes();
        unset($attributes['id']);
        $attributes['code'] = 'raw-advanced';
        $attributes['package_tracking_mode'] = 'PACKAGE_BALANCE';
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('item_package_mode_gate');
        DB::table('items')->insert($attributes);
    }

    public function test_raw_sql_cannot_rewrite_pinned_item_provenance(): void
    {
        $item = $this->item($this->category());
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('pinned');
        DB::table('items')->where('id', $item->id)->update(['package_tracking_source' => 'ITEM']);
    }

    public function test_raw_quantity_only_import_is_not_given_fabricated_category_provenance(): void
    {
        $item = $this->item($this->category());
        $attributes = $item->getAttributes();
        unset($attributes['id'], $attributes['package_tracking_mode'], $attributes['package_tracking_source'], $attributes['package_tracking_source_category_id']);
        $attributes['code'] = 'raw-quantity-only';
        $id = DB::table('items')->insertGetId($attributes);
        $imported = Item::query()->findOrFail($id);
        $this->assertSame(PackageTrackingMode::QuantityOnly, $imported->package_tracking_mode);
        $this->assertSame('UNATTRIBUTED_QUANTITY_ONLY', $imported->package_tracking_source);
    }

    public function test_raw_company_default_cannot_enable_tracking(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('company_package_mode_gate');
        DB::table('companies')->where('id', 1)->update(['default_package_tracking_mode' => 'PACKAGE_BALANCE']);
    }

    public function test_raw_category_default_cannot_enable_tracking(): void
    {
        $category = $this->category();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('category_package_mode_gate');
        DB::table('categories')->where('id', $category->id)->update(['default_package_tracking_mode' => 'CONTAINER_TRACKED']);
    }

    private function category(?Category $parent = null, ?string $mode = null): Category
    {
        return Category::query()->create([
            'code' => 'policy-category-'.++$this->sequence, 'name' => 'Policy category',
            'parent_id' => $parent?->id, 'default_valuation_method' => 'FIFO',
            'default_package_tracking_mode' => $mode,
        ]);
    }

    private function item(Category $category, array $extra = []): Item
    {
        return app(CreateItem::class)(array_merge([
            'code' => 'policy-item-'.++$this->sequence, 'name' => 'Policy item',
            'type' => 'RAW', 'stock_uom_id' => $this->unit->id, 'category_id' => $category->id,
        ], $extra));
    }
}
