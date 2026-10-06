<?php

namespace Tests\Architecture;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The physical-package operations (checkpoint recording and replay, opening/unpacking preview and posting; ADR 0082 is still proposed)
 * are reachable only from tests, seeders and each other: there is no route, console command or job. Their Actions check
 * `inventory.package-state.view` / `.open` themselves, but an HTTP entry point would also need a request-layer guard, a read policy
 * for the balances and lineage it returns (stock-position data, ADR 0083), cross-company tests and an audit-tracker row. This test makes
 * the moment one appears loud — docs/architecture/access-security-audit.md, Inventory.
 */
class PackageOperationsHaveNoEntryPointTest extends TestCase
{
    private const ACTIONS = ['PostPackageOpening', 'PreviewPackageOpening', 'RecordPackageCheckpoint', 'ReplayPackageCheckpoint'];

    public function test_no_http_console_or_job_code_reaches_the_package_operations(): void
    {
        $callers = [];
        $finder = (new Finder)->files()->in(base_path('app'))->name('*.php');
        foreach ($finder as $file) {
            $path = str_replace(base_path().'/', '', $file->getPathname());
            $entry = str_contains($path, '/Http/') || str_starts_with($path, 'app/Http/') || str_starts_with($path, 'app/Console/') || str_contains($path, '/Jobs/') || str_contains($path, '/Listeners/');
            if (! $entry) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach (self::ACTIONS as $action) {
                if (str_contains($source, $action)) {
                    $callers[] = "{$path} calls {$action}";
                }
            }
        }

        $this->assertSame([], $callers, 'An entry point now reaches the package operations. Give it a request-layer capability guard and a read policy, add cross-company tests and an audit-tracker row, then update this test.');
    }
}
