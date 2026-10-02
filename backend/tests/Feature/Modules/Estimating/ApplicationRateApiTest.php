<?php

namespace Tests\Feature\Modules\Estimating;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\ArchiveApplicationRate;
use App\Modules\Estimating\Actions\CreateApplicationRate;
use App\Modules\Estimating\Actions\UpdateApplicationRate;
use App\Modules\Estimating\Models\ApplicationRate;
use App\Modules\Estimating\Models\RateDimension;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class ApplicationRateApiTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    private Uom $sqm;

    private Uom $litre;

    private RateDimension $surfaceType;

    private int $smoothId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sqm = Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1']);
        $this->litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'PAINT', 'name' => 'Paint', 'default_valuation_method' => 'AVCO']);
        $this->item = Item::create(['code' => 'SHADE-4471', 'name' => 'Shade #4471', 'type' => 'FINISHED', 'stock_uom_id' => $this->litre->id, 'category_id' => $category->id]);
        $this->surfaceType = RateDimension::create(['code' => 'SURFACE', 'name' => 'Surface Type', 'is_required' => true]);
        $this->smoothId = $this->surfaceType->values()->create(['code' => 'SMOOTH', 'name' => 'Smooth plaster'])->id;
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/application-rates')->assertUnauthorized();
    }

    public function test_a_rate_can_be_created_with_dimension_values_and_listed(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.application-rate.manage']);

        $this->actingAs($user)->postJson('/api/v1/application-rates', [
            'item_id' => $this->item->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => '0.0833', 'uom_id' => $this->litre->id,
            'dimension_value_ids' => [$this->smoothId],
        ])->assertCreated()
            ->assertJsonPath('data.qty_per_work_unit', '0.083300')
            ->assertJsonPath('data.dimension_value_ids', [$this->smoothId]);

        $this->getJson('/api/v1/application-rates?filter[item_id]='.$this->item->id)
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_qty_per_work_unit_must_be_positive(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.application-rate.manage']);

        $this->actingAs($user)->postJson('/api/v1/application-rates', [
            'item_id' => $this->item->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => '0', 'uom_id' => $this->litre->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('qty_per_work_unit');
    }

    public function test_an_unknown_dimension_value_id_is_rejected(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.application-rate.manage']);

        $this->actingAs($user)->postJson('/api/v1/application-rates', [
            'item_id' => $this->item->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => '0.0833', 'uom_id' => $this->litre->id,
            'dimension_value_ids' => [999999],
        ])->assertUnprocessable()->assertJsonValidationErrors('dimension_value_ids.0');
    }

    public function test_an_exact_duplicate_combination_is_rejected(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.application-rate.manage']);
        $payload = [
            'item_id' => $this->item->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => '0.0833', 'uom_id' => $this->litre->id,
            'dimension_value_ids' => [$this->smoothId],
        ];

        $this->actingAs($user)->postJson('/api/v1/application-rates', $payload)->assertCreated();
        $this->postJson('/api/v1/application-rates', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('dimension_value_ids');
    }

    public function test_resolve_returns_the_winning_rate_and_method(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.application-rate.manage']);
        $rate = $this->actingAs($user)->postJson('/api/v1/application-rates', [
            'item_id' => $this->item->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => '0.0833', 'uom_id' => $this->litre->id,
            'dimension_value_ids' => [$this->smoothId],
        ])->json('data');

        $this->getJson('/api/v1/application-rates/resolve?item_id='.$this->item->id
            .'&work_uom_id='.$this->sqm->id.'&dimension_value_ids[]='.$this->smoothId)
            ->assertOk()
            ->assertJsonPath('data.method', 'EXACT_MATCH')
            ->assertJsonPath('data.rate.id', $rate['id']);
    }

    public function test_resolve_fails_loudly_with_no_match_and_no_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/application-rates/resolve?item_id='.$this->item->id
            .'&work_uom_id='.$this->sqm->id.'&dimension_value_ids[]='.$this->smoothId)
            ->assertUnprocessable();
    }

    public function test_item_id_cannot_be_changed_through_the_update_endpoint(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.application-rate.manage']);
        $rate = $this->actingAs($user)->postJson('/api/v1/application-rates', [
            'item_id' => $this->item->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => '0.0833', 'uom_id' => $this->litre->id,
        ])->json('data');

        $otherItem = Item::create(['code' => 'OTHER', 'name' => 'Other', 'type' => 'FINISHED', 'stock_uom_id' => $this->litre->id, 'category_id' => $this->item->category_id]);

        $this->patchJson("/api/v1/application-rates/{$rate['id']}", [
            'item_id' => $otherItem->id, 'qty_per_work_unit' => '0.0900',
        ])->assertOk()->assertJsonPath('data.item_id', $this->item->id)
            ->assertJsonPath('data.qty_per_work_unit', '0.090000');
    }

    public function test_destroy_removes_the_rate(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.application-rate.manage']);
        $rate = $this->actingAs($user)->postJson('/api/v1/application-rates', [
            'item_id' => $this->item->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => '0.0833', 'uom_id' => $this->litre->id,
        ])->json('data');

        $this->deleteJson("/api/v1/application-rates/{$rate['id']}")->assertNoContent();
        $this->getJson("/api/v1/application-rates/{$rate['id']}")->assertNotFound();
    }

    public function test_application_rate_writes_require_manage_but_reads_and_resolution_remain_available(): void
    {
        $rate = ApplicationRate::create([
            'item_id' => $this->item->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => '0.0833', 'uom_id' => $this->litre->id,
            'is_default' => true,
        ]);
        $reader = User::factory()->create();

        $this->actingAs($reader)->getJson('/api/v1/application-rates')->assertOk();
        $this->getJson("/api/v1/application-rates/{$rate->id}")->assertOk();
        $this->getJson('/api/v1/application-rates/resolve?item_id='.$this->item->id.'&work_uom_id='.$this->sqm->id)
            ->assertOk()->assertJsonPath('data.method', 'DEFAULT');
        $this->postJson('/api/v1/application-rates', [
            'item_id' => $this->item->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => '0.0900', 'uom_id' => $this->litre->id,
        ])->assertForbidden();
        $this->patchJson("/api/v1/application-rates/{$rate->id}", ['qty_per_work_unit' => '0.0900'])->assertForbidden();
        $this->deleteJson("/api/v1/application-rates/{$rate->id}")->assertForbidden();

        $this->assertSame('0.083300', $rate->fresh()->qty_per_work_unit);
        $this->assertSame(1, ApplicationRate::query()->count());
    }

    public function test_application_rate_actions_reject_an_unprivileged_non_http_caller(): void
    {
        $rate = ApplicationRate::create([
            'item_id' => $this->item->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => '0.0833', 'uom_id' => $this->litre->id,
        ]);
        $reader = User::factory()->create();
        $actions = [
            fn () => app(CreateApplicationRate::class)([
                'item_id' => $this->item->id, 'work_uom_id' => $this->sqm->id,
                'qty_per_work_unit' => '0.0900', 'uom_id' => $this->litre->id,
            ], $reader),
            fn () => app(UpdateApplicationRate::class)($rate, ['qty_per_work_unit' => '0.0900'], $reader),
            fn () => app(ArchiveApplicationRate::class)($rate, $reader),
        ];

        foreach ($actions as $action) {
            try {
                $action();
                $this->fail('An unprivileged Action caller was accepted.');
            } catch (DomainInvariantException $exception) {
                $this->assertStringContainsString('not permitted', $exception->getMessage());
            }
        }

        $this->assertSame('0.083300', $rate->fresh()->qty_per_work_unit);
        $this->assertSame(1, ApplicationRate::query()->count());
    }
}
