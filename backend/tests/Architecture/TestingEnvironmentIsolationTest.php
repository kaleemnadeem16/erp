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
}
