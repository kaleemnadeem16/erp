<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * ADR 0084 protects credit limits and ledger-account mappings by omitting the keys from every response, "everywhere their Resource
 * appears". Reading each Resource proves the keys are gated where they are written; this proves nothing else carries them. On a
 * seeded company it signs in as a user who holds **every registered capability except the four that open these fields**, calls every
 * readable GET endpoint — each list, and a show for the first rows of each list, with the includes that nest accounts — and scans the
 * whole JSON body, at any depth, for the protected keys. A second sweep as a user who holds the capabilities must find all of them,
 * so a clean first result cannot be a blind scan.
 *
 * Limits: it sees GET responses reachable from list rows with the includes named below. A new endpoint or include that nests these
 * entities is covered by the list-driven sweep automatically; an include it does not name is not.
 */
final class ProtectedFinancialFieldsNeverLeakTest extends TestCase
{
    use RefreshDatabase;

    private const FIELDS = ['credit_limit', 'income_account_id', 'expense_account_id', 'inventory_account_id', 'receivable_account_id', 'payable_account_id'];

    /** The capabilities that open the fields (ADR 0084); manage supplies the minimum read. */
    private const OPENING = ['parties.credit-limit.view', 'parties.credit-limit.manage', 'accounting.account-mapping.view', 'accounting.account-mapping.manage'];

    /** Includes that nest a customer, supplier, item or category, per list endpoint. */
    private const INCLUDES = [
        'api/v1/items' => 'category',
        'api/v1/categories' => 'parent,children',
        'api/v1/customer-accounts' => 'party',
        'api/v1/supplier-accounts' => 'party',
        'api/v1/purchase-orders' => 'lines,supplierSite',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
    }

    /** @return list<string> */
    private function everyCapabilityExcept(array $excluded): array
    {
        return array_values(array_diff(app(PermissionRegistry::class)->all(), $excluded));
    }

    /**
     * @return array{requests: int, found: array<string, list<string>>}
     */
    private function sweep(User $user): array
    {
        $uris = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && str_starts_with($route->uri(), 'api/v1/')) {
                $uris[] = $route->uri();
            }
        }
        $idLess = array_values(array_filter($uris, fn (string $uri): bool => ! str_contains($uri, '{')));
        $found = [];
        $requests = 0;

        $scan = function (string $url, array $json) use (&$found): void {
            $walk = function (mixed $node, string $path) use (&$walk, &$found, $url): void {
                if (! is_array($node)) {
                    return;
                }
                foreach ($node as $key => $value) {
                    if (is_string($key) && in_array($key, self::FIELDS, true)) {
                        $found[$key][] = "{$url} → {$path}.{$key}";
                    }
                    $walk($value, $path.'.'.$key);
                }
            };
            $walk($json, '$');
        };
        $get = function (string $url) use ($user, &$requests, $scan): ?array {
            $requests++;
            $response = $this->actingAs($user)->getJson($url);
            if ($response->getStatusCode() >= 300) {
                return null;
            }
            $json = $response->json();
            $scan($url, is_array($json) ? $json : []);

            return is_array($json) ? $json : null;
        };

        foreach ($idLess as $uri) {
            $base = '/'.$uri;
            foreach (array_unique([$base, isset(self::INCLUDES[$uri]) ? $base.'?include='.self::INCLUDES[$uri].'&per_page=100' : $base]) as $url) {
                $json = $get($url);
                $rows = is_array($json['data'] ?? null) && array_is_list($json['data']) ? array_slice($json['data'], 0, 3) : [];
                // A show route for the first rows of this list: the same URI with one id segment.
                foreach ($uris as $candidate) {
                    if (preg_match('#^'.preg_quote($uri, '#').'/\{[^/}]+\}$#', $candidate) === 1) {
                        foreach ($rows as $row) {
                            if (isset($row['id'])) {
                                $show = '/'.$uri.'/'.$row['id'];
                                $get(isset(self::INCLUDES[$uri]) ? $show.'?include='.self::INCLUDES[$uri] : $show);
                            }
                        }
                    }
                }
            }
        }

        return ['requests' => $requests, 'found' => $found];
    }

    public function test_no_readable_response_carries_a_protected_field_without_the_opening_capabilities(): void
    {
        $restricted = PermissionedActor::grant(User::factory()->create(), $this->everyCapabilityExcept(self::OPENING));
        $this->assertFalse($restricted->can('parties.credit-limit.view') || $restricted->can('accounting.account-mapping.view'), 'The probe user must lack the opening capabilities.');

        $result = $this->sweep($restricted);

        $this->assertGreaterThan(60, $result['requests'], 'The sweep reached too few endpoints to mean anything.');
        $this->assertSame([], $result['found'], "A protected financial field appears in a response to a user without its capability:\n".json_encode($result['found'], JSON_PRETTY_PRINT));
    }

    public function test_the_same_sweep_does_find_every_protected_field_for_a_user_who_holds_the_capabilities(): void
    {
        $holder = PermissionedActor::grant(User::factory()->create(), $this->everyCapabilityExcept([]));

        $found = array_keys($this->sweep($holder)['found']);
        sort($found);
        $expected = self::FIELDS;
        sort($expected);

        $this->assertSame($expected, $found, 'The scan must be able to see each protected key, or a clean result above proves nothing.');
    }
}
