<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A regression net for docs/architecture/access-security-audit.md, the read-side twin of WriteRouteGuardTest. A signed-in user who holds
 * *no capability at all* is sent to every API read endpoint that needs no record id; the ones that answer 200 must be named below
 * with the reason they are open. A new list endpoint that is open to every signed-in user therefore fails here until someone decides,
 * on purpose, that it is shared reference data (and records why) or gates it (docs/architecture/authorization.md, rules R2-R4).
 * The list must also stay honest: a route listed here that is no longer open fails too, so it shrinks as gates are added.
 *
 * Limits: it sees only id-less GET routes (a `show` route needs a record, and is covered by each module's own denied tests), and it
 * proves a response *exists*, not what is inside it — field projection (R3) is tested on response bodies in each module.
 */
class OpenReadRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** Open on purpose: shared reference data or the caller's own data. `uri => reason`. */
    private const OPEN_BY_DESIGN = [
        'api/v1/user' => 'The caller\'s own identity and effective permissions; the SPA needs them to decide what to show.',
        'api/v1/account' => 'The caller\'s own profile.',
        'api/v1/account/devices' => 'The caller\'s own signed-in devices (bearer tokens); the query starts from their tokens, never anyone else\'s (ADR 0088).',
        'api/v1/currencies' => 'Reference data every document form needs.',
        'api/v1/exchange-rates' => 'Shared rate lookup (writes need currency.exchange-rate.manage, A1); a Finance decision may narrow it later.',
        'api/v1/parties' => 'Shared trading-partner identities; nested financial fields are projected under ADR 0084 (ProtectedFinancialFieldsNeverLeakTest).',
        'api/v1/customer-accounts' => 'Shared account references/terms; credit and control mappings are projected under ADR 0084 (ProtectedFinancialFieldsNeverLeakTest).',
        'api/v1/supplier-accounts' => 'Shared account references/terms; control mappings are projected under ADR 0084 (ProtectedFinancialFieldsNeverLeakTest).',
        'api/v1/chart-of-accounts' => 'Shared account identity/structure catalogue, without balances or trading-partner/item mappings (ADR 0084).',
        'api/v1/uoms' => 'Reference data every quantity field needs.',
        'api/v1/item-packagings' => 'Reference data: the pack-size catalogue every item form offers; carries no price or stock figure.',
        'api/v1/categories' => 'Shared item lookup; GL mapping keys are projected by ADR 0084 field authority.',
        'api/v1/items' => 'Shared item lookup; GL mappings (ADR 0084) and cost/price fields are projected by capability.',
        'api/v1/item-quantity-units' => 'Reference data: the units an item may be stocked or bought in; no price or stock figure.',
        'api/v1/warehouses' => 'Shared lookup every stock and purchasing form needs; carries no stock figure.',
        'api/v1/locations' => 'Shared lookup every stock and purchasing form needs; carries no stock figure.',
        'api/v1/warehouse-location-roles' => 'Reference data: which location roles a warehouse offers; no stock figure.',
        'api/v1/inventory-settings' => 'Operational switches the SPA reads to shape forms; the one sensitive figure (the recalculation threshold) is projected by capability.',
        'api/v1/purchasing-settings' => 'Operational switches (over-receipt mode and tolerance, PO required) that shape receiving forms; no price.',
        'api/v1/approval-authorities/mine' => 'The caller\'s own approval authority only.',
        'api/v1/approval-requests/inbox' => 'Scoped to the caller\'s own assignments.',
        'api/v1/company' => 'The company profile: any signed-in user may read it (ADR 0081); each field enforces its own change permission. Registration and tax numbers are business identifiers printed on documents, so they are readable too — an owner may narrow that later.',
        'api/v1/approval-delegations' => 'Only delegations involving the caller; approvals.delegation.manage permits the company list (ADR 0070).',
        'api/v1/assembly-groups' => 'Shared estimating lookup; carries no cost (writes need estimating.assembly-group.manage).',
        'api/v1/assemblies' => 'Shared estimating lookup; commercial configuration is projected by capability (audit, 2026-09-30).',
        'api/v1/rate-dimensions' => 'Shared estimating lookup; carries no cost.',
        'api/v1/resource-rates' => 'Shared lookup; the numeric rate is projected by capability (audit, 2026-09-30).',
    ];

    /** Open today and NOT yet decided — each is an open item in the audit tracker, not an approval. `uri => where it is tracked`. */
    private const OPEN_PENDING_REVIEW = [];

    public function test_every_id_less_read_open_to_a_user_with_no_capability_is_named_with_a_reason(): void
    {
        $user = User::factory()->create();
        $this->assertSame([], $user->getAllPermissions()->all(), 'The probe user must hold no capability.');

        $open = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($route->uri(), 'api/v1/') || str_contains($route->uri(), '{')) {
                continue;
            }
            if ($this->actingAs($user)->getJson('/'.$route->uri())->getStatusCode() < 300) {
                $open[] = $route->uri();
            }
        }
        sort($open);

        $named = array_merge(array_keys(self::OPEN_BY_DESIGN), array_keys(self::OPEN_PENDING_REVIEW));
        $this->assertSame([], array_values(array_diff($open, $named)),
            'These reads answer 200 to a signed-in user with no capability. Gate them (authorization.md R2-R4), or add them to OPEN_BY_DESIGN with the reason they are shared reference data.');
        $this->assertSame([], array_values(array_diff($named, $open)),
            'These routes are listed as open but are no longer open (or no longer exist): remove them from the list.');
    }
}
