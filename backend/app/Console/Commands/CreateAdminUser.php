<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the first sign-in for a freshly migrated database.
 *
 * Migrations create company 1 but no user and no permissions, and demo:seed
 * deliberately refuses to run outside local/testing — so without this there is
 * no way to sign in to a new production deployment at all. Environment-
 * agnostic on purpose: it grants nothing by default beyond the permissions
 * named, so it cannot become a backdoor.
 */
final class CreateAdminUser extends Command
{
    protected $signature = 'erp:create-admin
        {--email= : The sign-in email address}
        {--name= : The person\'s display name}
        {--permissions=* : Permission names to grant, or "all" for every registered permission}';

    protected $description = 'Create an administrator sign-in for a freshly migrated database';

    public function handle(CompanyContext $companyContext): int
    {
        $email = (string) ($this->option('email') ?: $this->ask('Email address'));
        $name = (string) ($this->option('name') ?: $this->ask('Display name'));
        $password = (string) $this->secret('Password (input hidden)');

        try {
            $this->validatePassword($password);
        } catch (ValidationException $e) {
            $this->components->error($e->validator->errors()->first('password'));

            return self::FAILURE;
        }

        if (User::query()->where('email', strtolower($email))->exists()) {
            $this->components->error("A user with the email {$email} already exists.");

            return self::FAILURE;
        }

        /** @var list<string> $requested */
        $requested = (array) $this->option('permissions');

        $user = DB::transaction(function () use ($email, $name, $password, $requested, $companyContext): User {
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();

            // Permission checks resolve against the current team, which is the
            // company (see SetPermissionsTeam) — there is no HTTP request here
            // to set it, so it is set explicitly before granting.
            app(PermissionRegistrar::class)->setPermissionsTeamId($companyContext->id());

            $names = $requested === ['all']
                ? Permission::query()->pluck('name')->all()
                : $requested;
            if ($names !== []) {
                $user->givePermissionTo($names);
            }

            return $user;
        });

        $granted = $user->getAllPermissions()->count();
        $this->components->info("Created {$user->email} with {$granted} permission(s).");

        if ($granted === 0) {
            $this->components->warn('No permissions were granted — pass --permissions to grant them, or grant them later.');
        }

        return self::SUCCESS;
    }

    private function validatePassword(string $password): void
    {
        validator(['password' => $password], ['password' => ['required', Password::min(12)->letters()->numbers()->symbols()]])->validate();
    }
}
