<?php

namespace Tests\Architecture;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class TestingEnvironmentIsolationTest extends TestCase
{
    public function test_artisan_env_testing_overrides_docker_development_variables_before_laravel_boots(): void
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'config:show', 'database', '--env=testing', '--no-ansi'],
            base_path(),
            [
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => 'erp',
                // This subprocess checks the default isolation contract, even when
                // the parent suite uses a private tmpfs database.
                'TEST_DB_DATABASE' => false,
            ],
        );
        $process->mustRun();

        $this->assertMatchesRegularExpression('/default[ .]+testing/', $process->getOutput());
        $this->assertMatchesRegularExpression('/connections ⇁ testing ⇁ database[ .]+erp_testing/', $process->getOutput());
    }

    /**
     * @return array{int, string} exit code and error output of loading the PHPUnit bootstrap with these variables
     */
    private function loadBootstrap(array $environment): array
    {
        $process = new Process([PHP_BINARY, 'tests/bootstrap.php'], base_path(), $environment + [
            'TEST_DB_DATABASE' => false,
            'CI' => false,
            'ERP_ALLOW_SHARED_TEST_DB' => false,
        ]);
        $process->run();

        return [$process->getExitCode(), $process->getErrorOutput()];
    }

    public function test_the_suite_refuses_to_start_against_the_shared_erp_testing_database_outside_ci(): void
    {
        foreach ([[], ['TEST_DB_DATABASE' => 'erp_testing']] as $environment) {
            [$code, $error] = $this->loadBootstrap($environment);

            $this->assertSame(78, $code);
            $this->assertStringContainsString('run-tests-tmpfs.sh', $error);
        }
    }

    public function test_the_suite_starts_on_a_private_database_in_ci_or_with_the_explicit_override(): void
    {
        foreach ([['TEST_DB_DATABASE' => 'erp_testing_someone'], ['CI' => 'true'], ['ERP_ALLOW_SHARED_TEST_DB' => '1']] as $environment) {
            [$code] = $this->loadBootstrap($environment);

            $this->assertSame(0, $code, json_encode($environment));
        }
    }
}
