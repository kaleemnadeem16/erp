<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Support\CompanyContext;
use App\Support\PermissionRegistry;
use Illuminate\Console\Command;

/**
 * LOCAL DEVELOPMENT ONLY: gives (or takes away) the quotation view and prepare duties for one named developer login, so the quotation screens can be tried on
 * localhost. It refuses to run unless the dev preview is enabled (`APP_ENV=local` and `SALES_DEV_QUOTATION_DUTIES=true`); it never touches roles or mandates and
 * it never changes the shipped STAGED defaults.
 */
final class GrantQuotationDevPreview extends Command
{
    protected $signature = 'sales:dev-grant-quotation
        {email? : The developer login (defaults to the demo user)}
        {--revoke : Take the duties away again}';

    protected $description = 'LOCAL DEV ONLY: grant or revoke the staged quotation view/prepare duties for one login';

    public function handle(CompanyContext $companyContext, PermissionRegistry $permissions): int
    {
        if (! SalesDevPreview::enabled()) {
            $this->components->error('Refused: this needs APP_ENV=local and SALES_DEV_QUOTATION_DUTIES=true. The quotation duties stay STAGED everywhere else.');

            return self::FAILURE;
        }

        $email = strtolower((string) ($this->argument('email') ?? config('demo.user.email')));
        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            $this->components->error("No user with the email {$email} exists.");

            return self::FAILURE;
        }

        $permissions->registerForTeam($companyContext->id());
        if ($this->option('revoke')) {
            $user->revokePermissionTo(SalesDevPreview::DUTIES);
            $this->components->info("{$user->email} no longer holds ".implode(' and ', SalesDevPreview::DUTIES).'.');

            return self::SUCCESS;
        }
        $user->givePermissionTo(SalesDevPreview::DUTIES);
        $this->components->info("{$user->email} now holds ".implode(' and ', SalesDevPreview::DUTIES).' (local development only).');

        return self::SUCCESS;
    }
}
