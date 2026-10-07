<?php

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * ADR 0089 (Q26): record scope ("only my units") is applied surface by surface, and a limit protects only the surfaces that apply it.
 * This test makes that honest. Every stock-bearing API route must be named below with its status, so a new surface cannot ship silently
 * company-wide (the way every surface was before scope existed), and the pending list is the tracked work, not a hidden gap.
 *
 *  - SCOPED: limited to the warehouses a person's units own (WarehouseRecordScopeTest proves each on response bodies);
 *  - REFUSED_WHEN_LIMITED: a limited person gets 403 because the figure cannot be limited to a warehouse (overview, AVCO pools, and the company-wide
 *    cutover and recalculation workflows);
 *  - SHARED_REFERENCE_OR_CONFIGURATION: names, codes and company settings, open to whoever may read them by design;
 *  - PENDING: still company-wide, carries stock facts, and is the next slices of Q26 (empty: every inventory surface now has a status).
 * A route that is not listed fails; so does a listed route that no longer exists.
 */
class RecordScopeCoverageTest extends TestCase
{
    private const PATTERN = '#^api/v1/(stock-|avco-|inventory-|physical-count|negative-stock|recalculation|warehouse|locations|item-packag)#';

    private const SCOPED = [
        'GET api/v1/stock-layers', 'GET api/v1/stock-layers/{}', 'GET api/v1/stock-moves', 'GET api/v1/stock-moves/{}',
        'GET api/v1/physical-counts', 'POST api/v1/physical-counts', 'GET api/v1/physical-counts/{}',
        'POST api/v1/physical-counts/{}/lines', 'POST api/v1/physical-count-lines/{}/count',
        'POST api/v1/physical-counts/{}/post', 'POST api/v1/physical-counts/{}/cancel',
        'GET api/v1/negative-stock-override-requests',
        'POST api/v1/negative-stock-override-requests',
        'GET api/v1/negative-stock-override-requests/{}',
        'POST api/v1/negative-stock-override-requests/{}/decide',
        'POST api/v1/negative-stock-override-requests/{}/review',
    ];

    private const REFUSED_WHEN_LIMITED = [
        'GET api/v1/inventory-overview', 'GET api/v1/inventory-overview/summary', 'GET api/v1/avco-pools', 'GET api/v1/avco-pools/{}',
        'GET api/v1/recalculation-requests',
        'POST api/v1/recalculation-requests',
        'GET api/v1/recalculation-requests/{}',
        'POST api/v1/recalculation-impact-versions/{}/decide',
        'POST api/v1/recalculation-impact-versions/{}/execute',
        'POST api/v1/recalculation-impact-versions/{}/resume-regeneration',
        'GET api/v1/inventory-cutovers',
        'POST api/v1/inventory-cutovers',
        'GET api/v1/inventory-cutovers/{}',
        'GET api/v1/inventory-cutovers/{}/preview',
        'POST api/v1/inventory-cutovers/{}/approve',
        'POST api/v1/inventory-cutovers/{}/batches',
        'POST api/v1/inventory-cutovers/{}/commit',
        'POST api/v1/inventory-cutovers/{}/commit-open-purchase-orders',
        'POST api/v1/inventory-cutovers/{}/finalise',
        'POST api/v1/inventory-cutovers/{}/negative-openings',
        'POST api/v1/inventory-cutovers/{}/validate',
        'POST api/v1/inventory-cutover-negative-openings/{}/approve',
    ];

    private const SHARED_REFERENCE_OR_CONFIGURATION = [
        'GET api/v1/warehouses', 'GET api/v1/warehouses/{}', 'POST api/v1/warehouses', 'PATCH api/v1/warehouses/{}', 'DELETE api/v1/warehouses/{}',
        'GET api/v1/locations', 'GET api/v1/locations/{}', 'POST api/v1/locations', 'PATCH api/v1/locations/{}', 'DELETE api/v1/locations/{}',
        'GET api/v1/warehouse-location-roles', 'GET api/v1/warehouse-location-roles/{}', 'POST api/v1/warehouse-location-roles',
        'PATCH api/v1/warehouse-location-roles/{}', 'DELETE api/v1/warehouse-location-roles/{}',
        'GET api/v1/item-packagings', 'POST api/v1/item-packagings', 'PATCH api/v1/item-packagings/{}',
        'GET api/v1/inventory-settings', 'PATCH api/v1/inventory-settings', 'GET api/v1/inventory-policy-changes',
        'GET api/v1/stock-mix-policies', 'POST api/v1/stock-mix-policies',
    ];

    private const PENDING = [
    ];

    /** @return list<string> */
    private function stockRoutes(): array
    {
        $found = [];
        foreach (app('router')->getRoutes() as $route) {
            if (preg_match(self::PATTERN, $route->uri()) !== 1) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $found[] = $method.' '.preg_replace('/\{[^}]+\}/', '{}', $route->uri());
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    private function declared(): array
    {
        return array_merge(self::SCOPED, self::REFUSED_WHEN_LIMITED, self::SHARED_REFERENCE_OR_CONFIGURATION, self::PENDING);
    }

    public function test_every_stock_bearing_route_declares_its_record_scope_status(): void
    {
        $undeclared = array_values(array_diff($this->stockRoutes(), $this->declared()));
        sort($undeclared);

        $this->assertSame([], $undeclared, "Stock-bearing routes with no record-scope status. Apply the warehouse scope (docs/decisions/0089) and list the route under SCOPED or REFUSED_WHEN_LIMITED, or, if it is only a name/code lookup or company setting, under SHARED_REFERENCE_OR_CONFIGURATION. Do not add it to PENDING without recording why in the audit tracker.\n".implode("\n", $undeclared));
    }

    public function test_the_declared_routes_still_exist_and_are_declared_once(): void
    {
        $stale = array_values(array_diff($this->declared(), $this->stockRoutes()));
        $this->assertSame([], $stale, "Declared but no longer a route: remove it.\n".implode("\n", $stale));
        $this->assertSame([], array_values(array_diff_assoc($this->declared(), array_unique($this->declared()))), 'A route is declared under two statuses.');
    }

    public function test_the_scoped_surfaces_still_apply_the_warehouse_scope(): void
    {
        $base = base_path('app/Modules/Inventory/Http/Controllers/Api/V1/');
        foreach (['StockLayerController.php', 'StockMoveController.php', 'PhysicalCountController.php'] as $controller) {
            $this->assertStringContainsString('WarehouseScope::', (string) file_get_contents($base.$controller), "{$controller} no longer applies the warehouse scope");
        }
        foreach (['AvcoPool/IndexAvcoPoolRequest.php', 'AvcoPool/ShowAvcoPoolRequest.php', 'InventoryOverview/IndexInventoryOverviewRequest.php'] as $request) {
            $this->assertStringContainsString('WarehouseScope::unrestricted', (string) file_get_contents(base_path('app/Modules/Inventory/Http/Requests/'.$request)), "{$request} no longer refuses a limited person");
        }
    }
}
