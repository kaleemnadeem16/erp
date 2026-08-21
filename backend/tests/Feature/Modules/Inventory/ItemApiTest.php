<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\CustomFieldDefinition;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItemApiTest extends TestCase
{
    use RefreshDatabase;

    private Uom $uom;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uom = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1']);
        $this->category = Category::create(['code' => 'RAW', 'name' => 'Raw materials']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'PIG-001',
            'name' => 'Red pigment',
            'type' => 'RAW',
            'stock_uom_id' => $this->uom->id,
            'category_id' => $this->category->id,
            'valuation_method' => 'AVCO',
        ], $overrides);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/items')->assertUnauthorized();
    }

    public function test_an_item_can_be_created_and_fetched(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/items', $this->payload());

        $response->assertCreated();
        // The model lowercases code on write — the request layer normalises the
        // same way before validating, so this round-trips consistently.
        $response->assertJsonPath('data.code', 'pig-001');
        $id = $response->json('data.id');

        $this->getJson("/api/v1/items/{$id}")->assertOk()->assertJsonPath('data.name', 'Red pigment');
    }

    public function test_code_uniqueness_is_case_insensitive_because_the_model_lowercases_it(): void
    {
        $user = User::factory()->create();
        Item::create($this->payload(['code' => 'pig-001']));

        $this->actingAs($user)->postJson('/api/v1/items', $this->payload(['code' => 'PIG-001']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_a_nonexistent_category_id_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/items', $this->payload(['category_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
    }

    /**
     * No exists()/unique() rule can express "this category resolves, but sets no
     * default_valuation_method anywhere in its ancestry" — that's Item::booted()'s
     * own guard. Omitting valuation_method against a category with no default
     * proves that rejection surfaces as a 422 (App\Exceptions\DomainInvariantException),
     * not an uncaught 500.
     */
    public function test_no_resolvable_valuation_method_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();
        $categoryWithNoDefault = Category::create(['code' => 'OTHER', 'name' => 'Other']);

        $payload = $this->payload(['category_id' => $categoryWithNoDefault->id]);
        unset($payload['valuation_method']);

        $this->actingAs($user)->postJson('/api/v1/items', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valuation_method');
    }

    public function test_index_rejects_invalid_filters_and_pagination(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/items?filter[type]=NOT_A_TYPE')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filter.type');

        $this->getJson('/api/v1/items?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_index_can_filter_by_category_and_search_by_name(): void
    {
        $user = User::factory()->create();
        Item::create($this->payload(['code' => 'pig-001', 'name' => 'Red pigment']));
        Item::create($this->payload(['code' => 'pig-002', 'name' => 'Blue pigment']));

        $response = $this->actingAs($user)->getJson('/api/v1/items?filter[search]=red');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.code', 'pig-001');
    }

    public function test_index_validates_includes_and_sorts_descending(): void
    {
        $user = User::factory()->create();
        Item::create($this->payload(['code' => 'a', 'name' => 'Alpha']));
        Item::create($this->payload(['code' => 'z', 'name' => 'Zulu']));

        $this->actingAs($user)->getJson('/api/v1/items?include=unknown')
            ->assertUnprocessable()->assertJsonValidationErrors('include');
        $this->getJson('/api/v1/items?sort=unknown')
            ->assertUnprocessable()->assertJsonValidationErrors('sort');

        $this->getJson('/api/v1/items?sort=-name&include=category')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Zulu')
            ->assertJsonPath('data.0.category.code', 'RAW');
    }

    public function test_patch_updates_an_item_but_put_is_not_advertised(): void
    {
        $user = User::factory()->create();
        $item = Item::create($this->payload());

        $this->actingAs($user)->patchJson("/api/v1/items/{$item->id}", ['name' => 'Updated'])
            ->assertOk()->assertJsonPath('data.name', 'Updated');

        $this->putJson("/api/v1/items/{$item->id}", ['name' => 'Replacement'])
            ->assertMethodNotAllowed();
    }

    public function test_valuation_method_cannot_be_cleared_on_update(): void
    {
        $user = User::factory()->create();
        $item = Item::create($this->payload());

        $this->actingAs($user)->patchJson("/api/v1/items/{$item->id}", ['valuation_method' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valuation_method');

        $this->assertSame('AVCO', $item->refresh()->valuation_method->value);
    }

    public function test_show_can_include_category(): void
    {
        $user = User::factory()->create();
        $item = Item::create($this->payload());

        $response = $this->actingAs($user)->getJson("/api/v1/items/{$item->id}?include=category");

        $response->assertOk();
        $response->assertJsonPath('data.category.code', 'RAW');
    }

    public function test_custom_fields_reject_a_value_with_no_matching_definition(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/items', $this->payload([
            'custom_fields' => ['not_defined_anywhere' => 'x'],
        ]))->assertUnprocessable()->assertJsonValidationErrors('custom_fields');
    }

    /**
     * Phase 1 acceptance criterion: a custom field defined for items appears on
     * create/read with no code change — HasCustomFields was built in Phase 0 but
     * this is the first time any entity's custom_fields actually round-trips
     * through a real endpoint, not just get rejected by ValidCustomFields.
     */
    public function test_a_defined_custom_field_round_trips_through_create_and_read(): void
    {
        CustomFieldDefinition::create([
            'entity_type' => Item::customFieldEntityType(),
            'code' => 'batch_prefix',
            'label' => 'Batch prefix',
            'data_type' => 'string',
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/items', $this->payload([
            'custom_fields' => ['batch_prefix' => 'RED'],
        ]));

        $response->assertCreated();
        $response->assertJsonPath('data.custom_fields.batch_prefix', 'RED');
        $id = $response->json('data.id');

        $this->getJson("/api/v1/items/{$id}")->assertOk()->assertJsonPath('data.custom_fields.batch_prefix', 'RED');
    }

    public function test_an_item_is_soft_deleted(): void
    {
        $user = User::factory()->create();
        $item = Item::create($this->payload());

        $this->actingAs($user)->deleteJson("/api/v1/items/{$item->id}")->assertNoContent();

        $this->assertSoftDeleted('items', ['id' => $item->id]);
        $this->getJson("/api/v1/items/{$item->id}")->assertNotFound();
    }

    public function test_another_companys_item_is_not_visible(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Other Co', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $otherUom = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1']);
        $otherCategory = Category::create(['code' => 'RAW', 'name' => 'Raw materials']);
        $otherItem = Item::create($this->payload([
            'stock_uom_id' => $otherUom->id, 'category_id' => $otherCategory->id,
        ]));
        $this->app->instance(CompanyContext::class, new CompanyContext);

        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/api/v1/items/{$otherItem->id}")->assertNotFound();
    }
}
