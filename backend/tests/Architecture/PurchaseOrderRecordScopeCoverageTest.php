<?php

namespace Tests\Architecture;

use App\Modules\Purchasing\Support\PermissionVocabulary;
use App\Modules\Purchasing\Support\PurchaseOrderAccess;
use App\Modules\Purchasing\Support\PurchaseOrderScopeAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Q26 / ADR 0192: every purchase-order route states how record scope applies, so a new surface cannot ship company-wide by accident.
 *
 *  - SCOPED: reads apply the person's reach to the query / bound record; writes judge reach by the exact duty at HTTP entry and again inside the Action (404 when out of reach);
 *  - CREATES: no existing record is addressed; a limited creator is held to a reachable ship-to and a reachable replaced order;
 *  - UNSCOPED_WORKFLOW: an intentionally company-wide internal workflow with its own duty (documented in ADR 0192), never a PO read for a limited person;
 *  - NO_RECORD: addresses no purchase order (a calculation on submitted input).
 */
class PurchaseOrderRecordScopeCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const PATTERN = '#^api/v1/(purchase-order|inventory-cutovers/\{[^}]+\}/commit-open-purchase-orders)#';

    private const SCOPED = [
        'GET api/v1/purchase-orders', 'GET api/v1/purchase-orders/{}', 'PATCH api/v1/purchase-orders/{}', 'POST api/v1/purchase-orders/{}/submit',
        'POST api/v1/purchase-orders/{}/issue', 'POST api/v1/purchase-orders/{}/cancel', 'POST api/v1/purchase-order-lines/{}/short-close',
    ];

    private const CREATES = ['POST api/v1/purchase-orders'];

    private const UNSCOPED_WORKFLOW = ['POST api/v1/inventory-cutovers/{}/commit-open-purchase-orders'];

    private const NO_RECORD = ['POST api/v1/purchase-order-lines/preview'];

    /** @return list<string> */
    private function routes(): array
    {
        $found = [];
        foreach (app('router')->getRoutes() as $route) {
            if (preg_match(self::PATTERN, $route->uri()) !== 1) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'PUT']) as $method) {
                $found[] = $method.' '.preg_replace('/\{[^}]+\}/', '{}', $route->uri());
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    private function declared(): array
    {
        return array_merge(self::SCOPED, self::CREATES, self::UNSCOPED_WORKFLOW, self::NO_RECORD);
    }

    public function test_every_purchase_order_route_declares_its_record_scope_status(): void
    {
        $this->assertSame([], array_values(array_diff($this->routes(), $this->declared())), 'Purchase-order routes with no record-scope status (ADR 0192).');
    }

    public function test_declared_routes_still_exist_and_are_declared_once(): void
    {
        $this->assertSame([], array_values(array_diff($this->declared(), $this->routes())), 'Declared but no longer a route.');
        $this->assertSame($this->declared(), array_values(array_unique($this->declared())), 'A route is declared under two statuses.');
    }

    public function test_the_adapter_only_limits_duties_the_vocabulary_knows(): void
    {
        $known = PermissionVocabulary::all();
        $this->assertSame([], array_values(array_diff(PurchaseOrderScopeAdapter::LIMITABLE, $known)));
    }

    public function test_the_two_read_duty_lists_agree_with_the_read_gate(): void
    {
        $gate = (string) file_get_contents(base_path('app/Modules/Purchasing/Support/PurchasingReadAccess.php'));
        preg_match('/function purchaseOrders.*?\\]\\)/s', $gate, $m);
        preg_match_all("/'(purchasing\\.[a-z-]+\\.[a-z-]+)'/", $m[0] ?? '', $found);
        $declared = array_merge(PurchaseOrderAccess::READ_DUTIES, PurchaseOrderAccess::ADJACENT_READ_DUTIES);
        $this->assertSame([], array_values(array_diff($found[1], $declared)), 'A duty that reads purchase orders is missing from PurchaseOrderAccess: it would read them unscoped.');
    }

    public function test_money_on_a_purchase_order_is_never_judged_by_capability_alone(): void
    {
        // `canView` answers "does this person hold a commercial duty at all?". Anything that shows the money of an order must ask `forOrder` (or `companyWide`).
        $allowed = ['PurchasingCommercialAccess.php', 'SupplierOfferResource.php', 'MaintainSupplierOffer.php', 'PurchaseOrderApprovalSubject.php', 'IndexPurchaseOrderRequest.php', 'PurchaseOrderLineResource.php', 'PurchaseOrderController.php'];
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Modules/Purchasing'))) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')
                && str_contains((string) file_get_contents($file->getPathname()), 'PurchasingCommercialAccess::canView(') && ! in_array($file->getFilename(), $allowed, true)) {
                $offenders[] = $file->getFilename();
            }
        }
        $this->assertSame([], $offenders, 'Judge a purchase order\'s money with PurchasingCommercialAccess::forOrder() (or companyWide() for figures not tied to one order).');
    }
}
