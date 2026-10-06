<?php

namespace Tests\Architecture;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * A regression net for docs/architecture/access-security-audit.md: every API route that changes data (POST/PUT/PATCH/DELETE) must
 * be guarded by *something* a reviewer can point at — a Form Request whose `authorize()` checks a capability, an inline gate in the
 * controller, or a gate in the Action it calls — or be named below with the reason it needs none. A new write route with no guard at
 * all fails here instead of shipping open to every signed-in user (the way the exchange-rate, chart-of-account and Parties writes
 * once did). This proves a guard is *present*, not that it is *correct*: each slice still needs its own allowed/denied tests.
 *
 * It also records, as a ratchet, the write routes whose Action has no gate of its own (the guard is at the HTTP boundary only). A new
 * route may not join that list; shrinking it is the open "Action-level guard" work in the tracker.
 */
class WriteRouteGuardTest extends TestCase
{
    private const GATE = '/->can\(|->canAny\(|->authorize\(|Gate::|->hasPermissionTo\(|->hasAnyPermission\(|abort_unless\(|abort_if\(|PartyWriteAccess|NumberingAccess|ActionAuthorization::|->require\(|ApprovalGrant|assertMay/';

    /** Routes that need no capability, and why. `METHOD uri`. */
    private const NO_CAPABILITY_NEEDED = [
        'POST api/v1/login' => 'Sign-in: the caller is not authenticated yet.',
        'POST api/v1/forgot-password' => 'Recovery: the caller is not authenticated.',
        'POST api/v1/reset-password' => 'Recovery: proven by the emailed token.',
        'POST api/v1/logout' => 'Ends the caller\'s own session.',
        'PATCH api/v1/account' => 'The caller edits their own profile only.',
        'DELETE api/v1/account/devices/{device}' => 'The caller signs out one of their own devices: DeviceController starts from the caller\'s own tokens, so another person\'s device is a 404.',
        'PATCH api/v1/account/password' => 'The caller changes their own password, proving the current one.',
        'POST api/v1/approval-requests/{approval_request}/decide' => 'Decided by eligibility, not a fixed capability: DecideApproval checks authority, assignment, separation of duties and state.',
        'POST api/v1/approval-requests/{approval_request}/withdraw' => 'Only the submitter may withdraw: WithdrawApproval checks the actor.',
    ];

    /** POSTs that only compute an answer from the payload and persist nothing; they still need their request guard (checked above). */
    private const COMPUTE_ONLY = [
        'POST api/v1/numbering/preview', 'POST api/v1/purchase-order-lines/preview', 'POST api/v1/assemblies/{assembly}/material-preview',
    ];

    /** Write routes whose Action has no gate of its own — the guard is the Form Request/controller. Ratchet: it is now empty and must stay empty. */
    private const ACTION_WITHOUT_GATE = [];

    /** @return list<array{key: string, route: Route, source: string}> */
    private function writeRoutes(): array
    {
        $found = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) as $method) {
                $found[] = ['key' => $method.' '.$route->uri(), 'route' => $route, 'source' => $route->getActionName()];
            }
        }

        return $found;
    }

    /** @return array{request: bool|null, controller: bool, actions: list<bool>} */
    private function guards(Route $route): array
    {
        // An invokable controller is registered as the bare class name.
        [$class, $method] = str_contains($route->getActionName(), '@') ? explode('@', $route->getActionName()) : [$route->getActionName(), '__invoke'];
        $reflection = new ReflectionMethod($class, $method);
        $request = null;
        $actions = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $name = $type->getName();
            if (is_subclass_of($name, FormRequest::class)) {
                $authorize = new ReflectionMethod($name, 'authorize');
                $source = $this->methodSource($authorize);
                $request = (bool) preg_match(self::GATE, $source);
            } elseif (str_contains($name, '\\Actions\\')) {
                $actions[] = (bool) preg_match(self::GATE, (string) file_get_contents((new ReflectionClass($name))->getFileName()));
            }
        }
        $controllerFile = (string) file_get_contents((new ReflectionClass($class))->getFileName());

        return [
            'request' => $request,
            'controller' => (bool) preg_match(self::GATE, $this->methodSource($reflection)) || (bool) preg_match('/function assertMay/', $controllerFile) && str_contains($this->methodSource($reflection), 'assertMay'),
            'actions' => $actions,
        ];
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $lines = file($method->getFileName());

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    public function test_every_write_route_is_guarded_or_explained(): void
    {
        $unguarded = [];
        foreach ($this->writeRoutes() as ['key' => $key, 'route' => $route]) {
            if (isset(self::NO_CAPABILITY_NEEDED[$key])) {
                continue;
            }
            $g = $this->guards($route);
            if ($g['request'] !== true && ! $g['controller'] && ! in_array(true, $g['actions'], true)) {
                $unguarded[] = $key;
            }
        }

        $this->assertSame([], $unguarded, "Write routes with no capability guard (request authorize, controller gate or Action gate) and no entry in NO_CAPABILITY_NEEDED:\n".implode("\n", $unguarded));
    }

    public function test_the_explained_exceptions_still_exist(): void
    {
        $keys = array_column($this->writeRoutes(), 'key');
        foreach (array_keys(self::NO_CAPABILITY_NEEDED) as $key) {
            $this->assertContains($key, $keys, "{$key} is listed as needing no capability but no longer exists; remove it.");
        }
    }

    public function test_no_new_write_route_relies_on_the_http_boundary_alone(): void
    {
        $withoutActionGate = [];
        foreach ($this->writeRoutes() as ['key' => $key, 'route' => $route]) {
            $g = $this->guards($route);
            if ($g['actions'] !== [] && ! in_array(true, $g['actions'], true) && ! isset(self::NO_CAPABILITY_NEEDED[$key]) && ! in_array($key, self::COMPUTE_ONLY, true)) {
                $withoutActionGate[] = $key;
            }
        }
        sort($withoutActionGate);
        $known = self::ACTION_WITHOUT_GATE;
        sort($known);

        $this->assertSame([], array_values(array_diff($withoutActionGate, $known)), 'New write route(s) whose Action has no capability gate of its own. Gate the Action (CLI, jobs and integrations call Actions without HTTP), do not extend the ratchet list.');
        $this->assertSame([], array_values(array_diff($known, $withoutActionGate)), 'These routes now have an Action-level gate (or are gone): remove them from ACTION_WITHOUT_GATE so the ratchet tightens.');
    }

    /**
     * Write routes guarded only inside their Action, with no capability check at the request layer (R1 in authorization.md asks for
     * both, so an HTTP caller gets a 403, not a domain error). Ratchet: a route may not join; shrinking it is the open request-layer
     * sweep in the tracker.
     */
    private const REQUEST_WITHOUT_GATE = [];

    public function test_no_new_write_route_is_guarded_only_inside_its_action(): void
    {
        $actionOnly = [];
        foreach ($this->writeRoutes() as ['key' => $key, 'route' => $route]) {
            if (isset(self::NO_CAPABILITY_NEEDED[$key])) {
                continue;
            }
            $g = $this->guards($route);
            if ($g['request'] !== true && ! $g['controller']) {
                $actionOnly[] = $key;
            }
        }
        sort($actionOnly);
        $known = self::REQUEST_WITHOUT_GATE;
        sort($known);

        $this->assertSame([], array_values(array_diff($actionOnly, $known)), 'New write route(s) with no capability check in the Form Request or controller. Check the capability in authorize() so an HTTP caller gets a 403 (authorization.md R1); do not extend the ratchet list.');
        $this->assertSame([], array_values(array_diff($known, $actionOnly)), 'These routes now have a request-layer gate (or are gone): remove them from REQUEST_WITHOUT_GATE so the ratchet tightens.');
    }
}
