<?php

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * A generated client serialises a boolean query parameter as "true"/"false", which Laravel's
 * `boolean` rule rejects. The screens then rendered the 422 as an empty list, so a status filter
 * such as Warehouses' "Active" silently showed no records. ValidatesListQuery normalises the
 * filters a request itself declares boolean; these tests pin that for every such filter.
 */
class BooleanListFilterTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function booleanFilters(): array
    {
        return [
            'warehouses active' => ['/api/v1/warehouses', 'active'],
            'locations active' => ['/api/v1/locations', 'active'],
            'item suppliers active' => ['/api/v1/item-suppliers', 'active'],
            'item suppliers preferred' => ['/api/v1/item-suppliers', 'preferred'],
            'application rates is_default' => ['/api/v1/application-rates', 'is_default'],
            'stock layers open' => ['/api/v1/stock-layers', 'open'],
        ];
    }

    #[DataProvider('booleanFilters')]
    public function test_true_and_false_are_accepted_as_well_as_one_and_zero(string $url, string $filter): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['inventory.stock.view', 'inventory.movement.view']);
        if ($url === '/api/v1/item-suppliers') {
            PermissionedActor::grant($user, ['purchasing.item-supplier.view']);
        }

        foreach (['true', 'false', 'TRUE', '1', '0'] as $value) {
            $this->actingAs($user)->getJson("{$url}?filter[{$filter}]={$value}")
                ->assertOk();
        }
    }

    #[DataProvider('booleanFilters')]
    public function test_a_value_that_is_not_a_boolean_still_fails(string $url, string $filter): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['inventory.stock.view', 'inventory.movement.view']);
        if ($url === '/api/v1/item-suppliers') {
            PermissionedActor::grant($user, ['purchasing.item-supplier.view']);
        }

        $this->actingAs($user)->getJson("{$url}?filter[{$filter}]=yes")
            ->assertUnprocessable()->assertJsonValidationErrors("filter.{$filter}");
    }

    public function test_the_filter_actually_filters_rather_than_only_validating(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['inventory.stock.view', 'inventory.movement.view']);
        $this->warehouse('open-depot', 'Open Depot');
        $closed = $this->warehouse('closed-depot', 'Closed Depot');
        $closed->update(['active' => false]);

        foreach (['true' => ['open-depot'], 'false' => ['closed-depot'], '1' => ['open-depot'], '0' => ['closed-depot']] as $value => $expected) {
            $codes = $this->actingAs($user)->getJson("/api/v1/warehouses?filter[active]={$value}&sort=code")
                ->assertOk()->json('data.*.code');
            $this->assertSame($expected, $codes, "filter[active]={$value}");
        }
    }

    public function test_a_text_filter_is_never_treated_as_a_boolean(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['inventory.stock.view', 'inventory.movement.view']);
        $this->warehouse('true-north', 'True North Depot');
        $this->warehouse('other', 'Other Depot');

        // If "true" were rewritten to a boolean this would search for "1" and find nothing.
        $codes = $this->actingAs($user)->getJson('/api/v1/warehouses?filter[search]=true')->assertOk()->json('data.*.code');

        $this->assertSame(['true-north'], $codes);
    }

    private function warehouse(string $code, string $name): Warehouse
    {
        return app(CreateWarehouse::class)(['code' => $code, 'name' => $name, 'location_code' => 'main', 'location_name' => 'Main']);
    }
}
