<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Actions\ArchiveItemQuantityUnit;
use App\Modules\Inventory\Actions\CreateItemQuantityUnit;
use App\Modules\Inventory\Actions\UpdateItemQuantityUnit;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemQuantityUnit;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class ItemQuantityUnitApiTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    private Uom $each;

    protected function setUp(): void
    {
        parent::setUp();
        $this->each = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->item = Item::create(['code' => 'PIG', 'name' => 'Pigment', 'type' => 'RAW', 'stock_uom_id' => $this->each->id, 'category_id' => $category->id]);
    }

    public function test_http_and_direct_actions_deny_conversion_writes_without_capability(): void
    {
        $actor = User::factory()->create();
        $box = Uom::create(['code' => 'box', 'name' => 'Box', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $row = ItemQuantityUnit::create(['item_id' => $this->item->id, 'uom_id' => $box->id, 'factor' => '12']);
        $this->actingAs($actor)->postJson('/api/v1/item-quantity-units', [])->assertForbidden();
        $this->patchJson("/api/v1/item-quantity-units/{$row->id}", ['factor' => '24'])->assertForbidden();
        $this->deleteJson("/api/v1/item-quantity-units/{$row->id}")->assertForbidden();
        foreach ([
            fn () => app(CreateItemQuantityUnit::class)([], $actor),
            fn () => app(UpdateItemQuantityUnit::class)($row, ['factor' => '24'], $actor),
            fn () => app(ArchiveItemQuantityUnit::class)($row, $actor),
        ] as $write) {
            try {
                $write();
                $this->fail('Unprivileged Action must be denied.');
            } catch (DomainInvariantException) {
                $this->assertDatabaseHas('item_quantity_units', ['id' => $row->id, 'factor' => '12']);
            }
        }
    }

    public function test_pack_override_round_trips_and_is_filterable_by_item(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['inventory.item.manage']);
        $box = Uom::create(['code' => 'box', 'name' => 'Box of 12', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);

        $id = $this->actingAs($user)->postJson('/api/v1/item-quantity-units', [
            'item_id' => $this->item->id, 'uom_id' => $box->id, 'factor' => '12', 'quantity_precision' => '1',
        ])->assertCreated()
            ->assertJsonPath('data.factor', '12.000000000')
            ->assertJsonPath('data.quantity_precision', '1.000000000')
            ->json('data.id');

        $this->getJson('/api/v1/item-quantity-units?filter[item_id]='.$this->item->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        $this->getJson("/api/v1/item-quantity-units/{$id}?include=item,uom")
            ->assertOk()->assertJsonPath('data.item.code', 'pig')->assertJsonPath('data.uom.code', 'box');
    }

    public function test_only_one_override_per_item_and_uom_is_allowed(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['inventory.item.manage']);
        $box = Uom::create(['code' => 'box', 'name' => 'Box of 12', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        ItemQuantityUnit::create(['item_id' => $this->item->id, 'uom_id' => $box->id, 'factor' => '12']);

        $this->actingAs($user)->postJson('/api/v1/item-quantity-units', [
            'item_id' => $this->item->id, 'uom_id' => $box->id, 'factor' => '24',
        ])->assertUnprocessable()->assertJsonValidationErrors('uom_id');
    }

    public function test_factor_must_be_positive(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['inventory.item.manage']);
        $box = Uom::create(['code' => 'box', 'name' => 'Box of 12', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);

        $this->actingAs($user)->postJson('/api/v1/item-quantity-units', [
            'item_id' => $this->item->id, 'uom_id' => $box->id, 'factor' => '0',
        ])->assertUnprocessable()->assertJsonValidationErrors('factor');
    }

    public function test_destroy_hard_deletes_the_override(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['inventory.item.manage']);
        $box = Uom::create(['code' => 'box', 'name' => 'Box of 12', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $row = ItemQuantityUnit::create(['item_id' => $this->item->id, 'uom_id' => $box->id, 'factor' => '12']);

        $this->actingAs($user)->deleteJson("/api/v1/item-quantity-units/{$row->id}")->assertNoContent();
        $this->assertDatabaseMissing('item_quantity_units', ['id' => $row->id]);
    }

    public function test_identity_cannot_be_repointed_after_creation(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['inventory.item.manage']);
        $box = Uom::create(['code' => 'box', 'name' => 'Box of 12', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $roll = Uom::create(['code' => 'roll', 'name' => 'Roll', 'dimension' => UomDimension::Length, 'factor_to_base' => '1']);
        $row = ItemQuantityUnit::create(['item_id' => $this->item->id, 'uom_id' => $box->id, 'factor' => '12']);

        $this->actingAs($user)->patchJson("/api/v1/item-quantity-units/{$row->id}", [
            'uom_id' => $roll->id, 'factor' => '50',
        ])->assertOk();

        $row->refresh();
        $this->assertSame($box->id, $row->uom_id);
        $this->assertSame('50.000000000', $row->factor);

        $this->expectException(RuntimeException::class);
        $row->update(['uom_id' => $roll->id]);
    }

    public function test_uom_reference_cannot_cross_company_boundary(): void
    {
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompany) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $otherUom = Uom::create(['code' => 'box', 'name' => 'Box', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $this->app->instance(CompanyContext::class, new class(1) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->expectException(RuntimeException::class);
        ItemQuantityUnit::create(['item_id' => $this->item->id, 'uom_id' => $otherUom->id, 'factor' => '12']);
    }
}
