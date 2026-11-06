<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Foundation\Http\FormRequest;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/** Family access is a separate resource from commercial revision operations, with its own exact inventory. */
class QuotationFamilyAccessSurfaceTest extends TestCase
{
    private const SURFACES = [
        'GET api/v1/quotation-families/{family}/access' => ['ReadQuotationFamilyAccess', 'QuotationFamilyAccessResource'],
        'GET api/v1/quotation-families/{family}/access-candidates' => ['ReadQuotationAccessCandidates', 'QuotationAccessCandidateResource'],
        'POST api/v1/quotation-families/{family}/reassign-owner' => ['ReassignQuotationOwner', 'QuotationOwnershipOutcomeResource'],
        'POST api/v1/quotation-families/{family}/change-access-unit' => ['ChangeQuotationAccessUnit', 'QuotationOwnershipOutcomeResource'],
        'POST api/v1/quotation-families/{family}/grant-access' => ['GrantQuotationAccess', 'QuotationShareOutcomeResource'],
        'POST api/v1/quotation-families/{family}/revoke-access' => ['RevokeQuotationAccess', 'QuotationShareOutcomeResource'],
    ];

    public function test_every_family_access_route_has_a_request_real_action_and_explicit_safe_resource(): void
    {
        $found = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/quotation-families/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $key = $method.' '.$route->uri();
                $found[] = $key;
                $this->assertArrayHasKey($key, self::SURFACES);
                [$action, $resource] = self::SURFACES[$key];
                [$controller, $handler] = explode('@', $route->getActionName());
                $reflection = new ReflectionMethod($controller, $handler);
                $types = array_map(fn ($parameter): string => ($type = $parameter->getType()) instanceof ReflectionNamedType ? $type->getName() : '', $reflection->getParameters());
                $this->assertTrue((bool) array_filter($types, fn (string $type): bool => is_subclass_of($type, FormRequest::class)), $key);
                $this->assertTrue((bool) array_filter($types, fn (string $type): bool => str_ends_with($type, '\\'.$action) && str_contains($type, '\\Actions\\')), $key);
                $this->assertStringContainsString($resource, (string) file_get_contents($reflection->getFileName()));
            }
        }
        $this->assertEqualsCanonicalizing(array_keys(self::SURFACES), $found);
    }
}
