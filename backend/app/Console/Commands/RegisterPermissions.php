<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CompanyContext;
use App\Support\PermissionRegistry;
use Illuminate\Console\Command;

final class RegisterPermissions extends Command
{
    protected $signature = 'permission:register';

    protected $description = 'Register the application permission vocabulary for the current company';

    public function handle(CompanyContext $companyContext, PermissionRegistry $permissions): int
    {
        $registered = $permissions->registerForTeam($companyContext->id());

        $this->components->info("Registered {$registered} application permission(s) for company {$companyContext->id()}.");

        return self::SUCCESS;
    }
}
