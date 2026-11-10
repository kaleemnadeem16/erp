<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Demo\DemoCompanyGuard;
use App\Support\Demo\DemoCredentials;
use Database\Seeders\Demo\DemoDatabaseSeeder;
use Illuminate\Console\Command;

final class SeedDemoData extends Command
{
    protected $signature = 'demo:seed';

    protected $description = 'Create the development demonstration dataset (create-only: existing data is never changed)';

    public function handle(DemoDatabaseSeeder $seeder): int
    {
        if (! DemoCompanyGuard::allowedEnvironment()) {
            $this->components->error(DemoCompanyGuard::ENVIRONMENT_MESSAGE);

            return self::FAILURE;
        }

        $seeder->setContainer($this->laravel);
        $seeder->setCommand($this);
        $seeder();

        $this->newLine();
        $this->components->info('Demo data is ready. Sign in with '.config('demo.user.email').'.');
        if (($generated = DemoCredentials::generated()) !== null) {
            // Shown once: nothing stores it in plain text, and a rerun never changes an existing account's password.
            $this->components->warn('Generated demo password (shown once; set DEMO_USER_PASSWORD to choose your own): '.$generated);
        }

        return self::SUCCESS;
    }
}
