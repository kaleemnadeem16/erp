<?php

namespace Tests\Architecture;

use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\E2E\RealBackend\RealBackendAuthority;
use Tests\TestCase;

/**
 * The real-backend browser harness (tests/E2E/RealBackend, docs/architecture/real-backend-browser-tests.md) activates duties and rebinds the company resolver in ITS OWN test process
 * only. These guards pin that none of it can become a production permission grant, runtime bypass or public activation mechanism.
 */
class RealBackendHarnessIsolationTest extends TestCase
{
    private const FORBIDDEN = ['Tests\\E2E', 'RealBackendAuthority', 'TokenCompanyContext', 'NoBindingFamilies', 'ERP_E2E_BACKEND', 'ERP_E2E_WORLD_FILE', 'E2E/RealBackend'];

    /** @return iterable<string> */
    private function productionFiles(): iterable
    {
        foreach (['app', 'bootstrap', 'config', 'routes', 'database', 'public'] as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && preg_match('/\.(php|json|env|ini)$/', $file->getFilename())) {
                    yield $file->getPathname();
                }
            }
        }
    }

    public function test_no_production_file_references_the_harness_or_its_switches(): void
    {
        $hits = [];
        foreach ($this->productionFiles() as $file) {
            $text = (string) file_get_contents($file);
            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($text, $needle)) {
                    $hits[] = str_replace(base_path().'/', '', $file)." mentions [$needle]";
                }
            }
        }
        $this->assertSame([], $hits);
    }

    public function test_the_harness_is_autoload_dev_only_and_in_no_test_suite(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('Tests\\', $composer['autoload']['psr-4'] ?? [], 'tests are autoload-dev only, so a --no-dev production install cannot even load the harness');
        $this->assertSame('tests/', $composer['autoload-dev']['psr-4']['Tests\\']);
        $phpunit = (string) file_get_contents(base_path('phpunit.xml'));
        $this->assertStringNotContainsString('tests/E2E', $phpunit, 'the world builder must never run as part of a normal suite');
    }

    public function test_the_harness_refuses_every_environment_but_its_own(): void
    {
        $keep = ['ERP_E2E_BACKEND' => getenv('ERP_E2E_BACKEND'), 'APP_ENV' => getenv('APP_ENV'), 'TEST_DB_DATABASE' => getenv('TEST_DB_DATABASE')];
        try {
            foreach ([
                ['ERP_E2E_BACKEND' => false, 'APP_ENV' => 'testing', 'TEST_DB_DATABASE' => 'erp_testing_x_e2e'],
                ['ERP_E2E_BACKEND' => '1', 'APP_ENV' => 'production', 'TEST_DB_DATABASE' => 'erp_testing_x_e2e'],
                ['ERP_E2E_BACKEND' => '1', 'APP_ENV' => 'local', 'TEST_DB_DATABASE' => 'erp_testing_x_e2e'],
                ['ERP_E2E_BACKEND' => '1', 'APP_ENV' => 'testing', 'TEST_DB_DATABASE' => 'erp'],
                ['ERP_E2E_BACKEND' => '1', 'APP_ENV' => 'testing', 'TEST_DB_DATABASE' => 'erp_testing'],
                ['ERP_E2E_BACKEND' => '1', 'APP_ENV' => 'testing', 'TEST_DB_DATABASE' => 'erp_testing_x'],
                ['ERP_E2E_BACKEND' => '1', 'APP_ENV' => 'testing', 'TEST_DB_DATABASE' => 'erp_e2e'],
                ['ERP_E2E_BACKEND' => '1', 'APP_ENV' => 'testing', 'TEST_DB_DATABASE' => false],
            ] as $env) {
                foreach ($env as $name => $value) {
                    $value === false ? putenv($name) : putenv("$name=$value");
                }
                $this->assertNotNull(RealBackendAuthority::refusal(), json_encode($env));
            }
            putenv('ERP_E2E_BACKEND=1');
            putenv('APP_ENV=testing');
            putenv('TEST_DB_DATABASE=erp_testing_agent1_x_e2e');
            $this->assertNull(RealBackendAuthority::refusal());
        } finally {
            foreach ($keep as $name => $value) {
                $value === false ? putenv($name) : putenv("$name=$value");
            }
        }
    }

    public function test_the_shipped_manifests_and_the_dev_preview_still_leave_every_handover_duty_staged(): void
    {
        $shipped = SalesDutyManifest::shipped();
        foreach ([P::HANDOVER_PREPARE, P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE] as $duty) {
            $this->assertFalse($shipped->isActive($duty), "$duty ships STAGED");
            $this->assertNotContains($duty, SalesDevPreview::activated(), "$duty is not switched on by the local preview either");
            $this->assertArrayHasKey($duty, SalesDevPreview::EXCLUDED);
        }
        $this->assertSame([], JobDutyManifest::shipped()->activeNames(), 'no Jobs duty ships ACTIVE');
    }
}
