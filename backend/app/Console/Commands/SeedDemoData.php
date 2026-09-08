<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoDatabaseSeeder;
use Illuminate\Console\Command;

final class SeedDemoData extends Command
{
    protected $signature = 'demo:seed';

    protected $description = 'Create or refresh the development demonstration dataset';

    public function handle(DemoDatabaseSeeder $seeder): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('Demo data may only be seeded in local or testing environments.');

            return self::FAILURE;
        }

        $seeder->setContainer($this->laravel);
        $seeder->setCommand($this);
        $seeder();

        $this->newLine();
        $this->components->info('Demo data is ready. Sign in with '.config('demo.user.email').'.');

        return self::SUCCESS;
    }
}
