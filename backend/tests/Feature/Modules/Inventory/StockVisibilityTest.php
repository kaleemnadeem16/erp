<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ValuationMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Inventory\Concerns\BuildsOutboundRecalculation;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * ADR 0083: stock reads are three separate capabilities — availability (the item-level summary), stock (the position by
 * warehouse/location/batch/serial, layers and pools) and movement (the ledger) — each independent of `inventory.valuation.view`.
 * Every surface is tried as someone with each capability alone and as someone with none, on the response *bodies* and with the
 * includes and filters that could otherwise reach a deeper layer.
 */
class StockVisibilityTest extends TestCase
{
    use BuildsOutboundRecalculation, RefreshDatabase;

    private const CAPS = ['availability' => 'inventory.availability.view', 'stock' => 'inventory.stock.view', 'movement' => 'inventory.movement.view'];

    private int $moveId;

    private int $layerId;

    private int $poolId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture(ValuationMethod::Avco);
        $this->receive($this->location('a'), '5', '10', '2026-01-01', 1);
        $this->moveId = StockMove::query()->firstOrFail()->id;
        $this->layerId = StockLayer::query()->firstOrFail()->id;
        $this->poolId = (int) DB::table('avco_pools')->value('id');
    }

    private function holder(string ...$permissions): User
    {
        return PermissionedActor::grant(User::factory()->create(), $permissions);
    }

    /** @return array<string, array{string, list<string>}> endpoint (with {id}) => [kind, ...] */
    private function surfaces(): array
    {
        return [
            '/api/v1/inventory-overview' => ['availability'], '/api/v1/inventory-overview/summary' => ['availability'],
            '/api/v1/stock-layers' => ['stock'], '/api/v1/stock-layers/{layer}' => ['stock'],
            '/api/v1/avco-pools' => ['stock'], '/api/v1/avco-pools/{pool}' => ['stock'],
            '/api/v1/stock-moves' => ['movement'], '/api/v1/stock-moves/{move}' => ['movement'],
        ];
    }

    private function url(string $template): string
    {
        return str_replace(['{layer}', '{pool}', '{move}'], [$this->layerId, $this->poolId, $this->moveId], $template);
    }

    public function test_each_surface_opens_only_to_its_own_capability(): void
    {
        $sets = [
            'nobody' => [], 'valuation only' => ['inventory.valuation.view'], 'availability' => ['inventory.availability.view'],
            'stock' => ['inventory.stock.view'], 'movement' => ['inventory.movement.view'],
        ];
        // Holding `stock` also gives the availability summary (the wider layer covers the narrower); nothing else crosses over.
        $allowed = [
            'availability' => ['/api/v1/inventory-overview', '/api/v1/inventory-overview/summary'],
            'stock' => ['/api/v1/inventory-overview', '/api/v1/inventory-overview/summary', '/api/v1/stock-layers', '/api/v1/stock-layers/{layer}', '/api/v1/avco-pools', '/api/v1/avco-pools/{pool}'],
            'movement' => ['/api/v1/stock-moves', '/api/v1/stock-moves/{move}'],
        ];

        foreach ($sets as $name => $permissions) {
            $user = $this->holder(...$permissions);
            foreach (array_keys($this->surfaces()) as $template) {
                $response = $this->actingAs($user)->getJson($this->url($template));
                in_array($template, $allowed[$name] ?? [], true) ? $response->assertOk() : $response->assertForbidden();
            }
        }
    }

    public function test_an_unauthenticated_caller_gets_nothing(): void
    {
        foreach (array_keys($this->surfaces()) as $template) {
            $this->getJson($this->url($template))->assertUnauthorized();
        }
    }

    public function test_includes_filters_and_sorts_cannot_reach_a_layer_the_caller_lacks(): void
    {
        $availability = $this->holder('inventory.availability.view');
        $movement = $this->holder('inventory.movement.view');

        // The deeper layers are refused before any query parameter is looked at.
        foreach (['/api/v1/stock-layers?include=location,batch&filter[open]=true', '/api/v1/stock-layers?sort=-created_at', '/api/v1/avco-pools?include=warehouse,batch&filter[status]=ACTIVE'] as $url) {
            $this->actingAs($availability)->getJson($url)->assertForbidden();
            $this->actingAs($movement)->getJson($url)->assertForbidden();
        }
        foreach (['/api/v1/stock-moves?include=item,to_location,valuation_entries&filter[warehouse_id]=1', '/api/v1/stock-moves?filter[search]=x'] as $url) {
            $this->actingAs($availability)->getJson($url)->assertForbidden();
        }

        // The summary offers no way to ask where stock is: a location or warehouse filter is simply not accepted.
        $this->actingAs($availability)->getJson('/api/v1/inventory-overview?filter[location_id]=1')->assertUnprocessable();
        $this->actingAs($availability)->getJson('/api/v1/inventory-overview?filter[warehouse_id]=1')->assertUnprocessable();
    }

    public function test_the_availability_summary_carries_no_location_batch_or_value(): void
    {
        $body = $this->actingAs($this->holder('inventory.availability.view'))->getJson('/api/v1/inventory-overview')->assertOk()->getContent();
        foreach (['location', 'warehouse', 'batch', 'serial', 'on_hand_value', 'available_value', 'unit_cost'] as $key) {
            $this->assertStringNotContainsString($key, $body, $key);
        }
        $this->assertStringContainsString('on_hand_qty', $body);
    }

    public function test_the_movement_ledger_alone_shows_no_balances_or_value(): void
    {
        $body = $this->actingAs($this->holder('inventory.movement.view'))->getJson("/api/v1/stock-moves/{$this->moveId}?include=valuation_entries")->assertOk()->getContent();
        $this->assertStringNotContainsString('unit_cost', $body);
        $this->assertStringNotContainsString('valuation_entries', $body);
        // …and it grants none of the position layers.
        $this->actingAs($this->holder('inventory.movement.view'))->getJson('/api/v1/inventory-overview')->assertForbidden();
    }
}
