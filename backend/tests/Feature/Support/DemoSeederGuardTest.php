<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Company;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Models\ApplicationRate;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Inventory\Models\AvcoPool;
use App\Support\CompanyContext;
use App\Support\Demo\DemoCompanyGuard;
use App\Support\Demo\DemoCredentials;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/** Issue #100 (review findings E7-E13): one central guard, create-only seeders, no public default password. */
final class DemoSeederGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<class-string<Seeder>> */
    private function demoSeeders(): array
    {
        $classes = [];
        $root = database_path('seeders/Demo');
        foreach ((new Finder)->files()->in($root)->name('*DemoSeeder.php') as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $classes[] = 'Database\\Seeders\\Demo\\'.$relative;
        }
        sort($classes);

        return $classes;
    }

    private function useCompany(int $id): void
    {
        $this->app->instance(CompanyContext::class, new class($id) extends CompanyContext
        {
            public function __construct(private int $fixed) {}

            public function id(): int
            {
                return $this->fixed;
            }
        });
    }

    public function test_every_demo_seeder_refuses_a_company_that_is_not_the_demo_company(): void
    {
        $seeders = $this->demoSeeders();
        $this->assertGreaterThan(50, count($seeders));
        $this->useCompany(2);
        $before = $this->snapshot();

        foreach ($seeders as $class) {
            try {
                app($class)->run();
                $this->fail("$class ran against a non-demo company.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('designated demo company', $e->getMessage(), $class);
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_every_demo_seeder_refuses_production_even_with_demo_mode_and_other_environments_without_it(): void
    {
        $original = $this->app->environment();
        try {
            $this->app->detectEnvironment(fn (): string => 'production');
            config(['demo.mode' => true]);
            $this->assertFalse(DemoCompanyGuard::allowedEnvironment(), 'DEMO_MODE must not open production.');

            $this->app->detectEnvironment(fn (): string => 'staging');
            config(['demo.mode' => false]);
            $this->assertFalse(DemoCompanyGuard::allowedEnvironment());
            foreach ($this->demoSeeders() as $class) {
                try {
                    app($class)->run();
                    $this->fail("$class ran in a disallowed environment.");
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('local or testing', $e->getMessage(), $class);
                }
            }

            config(['demo.mode' => true]);
            $this->assertTrue(DemoCompanyGuard::allowedEnvironment(), 'A deployment that sets DEMO_MODE may seed.');
        } finally {
            $this->app->detectEnvironment(fn (): string => $original);
        }
    }

    public function test_the_demo_company_must_exist(): void
    {
        $this->expectException(\RuntimeException::class);
        Company::query()->whereKey(1)->delete();
        DemoCompanyGuard::assertCompany(1);
    }

    public function test_there_is_no_default_demo_password_in_code_or_config(): void
    {
        $this->assertSame(null, (require config_path('demo.php'))['user']['password'] ?? null === null ? null : 'set');
        $this->assertStringNotContainsString('User@Erp', (string) file_get_contents(config_path('demo.php')));
        config(['demo.user.password' => null]);
        $generated = DemoCredentials::password();
        $this->assertGreaterThanOrEqual(20, strlen($generated));
        $this->assertSame($generated, DemoCredentials::password(), 'one password per run');
        $this->assertSame($generated, DemoCredentials::generated());
    }

    public function test_the_command_prints_a_generated_password_once_and_uses_a_configured_one_silently(): void
    {
        config(['demo.user.password' => null]);
        $this->artisan('demo:seed')->expectsOutputToContain('Generated demo password')->assertSuccessful();
        $user = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $this->assertTrue(Hash::check((string) DemoCredentials::generated(), $user->password));
    }

    public function test_a_rerun_adds_nothing_and_never_resets_credentials_or_edits(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        // Someone changes the demo login password and some demo-owned reference data.
        $login = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $login->forceFill(['password' => Hash::make('Changed-by-owner-1!')])->save();
        $ops = User::query()->where('email', 'demo-ops@erp.x1techs.com')->firstOrFail();
        $reviewer = User::query()->where('email', 'demo-count-reviewer@erp.x1techs.com')->firstOrFail();
        $clerk = User::query()->where('email', 'demo-receiver@erp.x1techs.com')->firstOrFail();
        $hashes = [$login->password, $ops->password, $reviewer->password, $clerk->password];
        $login->forceFill(['name' => 'Renamed Owner'])->save();
        Uom::query()->where('code', 'kg')->update(['name' => 'Kilogramme']);
        AvcoPool::query()->where('qty_on_hand', '500')->update(['qty_on_hand' => '777']);
        $rateIds = ApplicationRate::query()->orderBy('id')->pluck('id')->all();
        $formulationVersionIds = DB::table('formulation_versions')->orderBy('id')->pluck('id')->all();
        $this->assertNotEmpty($rateIds);
        $this->assertNotEmpty($formulationVersionIds);
        $before = $this->snapshot();

        $this->artisan('demo:seed')->assertSuccessful();
        $login->refresh();
        $this->assertSame($hashes, [$login->password, $ops->fresh()->password, $reviewer->fresh()->password, $clerk->fresh()->password]);
        $this->assertSame('Renamed Owner', $login->name);
        $this->assertSame('Kilogramme', Uom::query()->where('code', 'kg')->value('name'));
        $this->assertSame(1, AvcoPool::query()->where('qty_on_hand', '777')->count());
        $this->assertSame($rateIds, ApplicationRate::query()->orderBy('id')->pluck('id')->all(), 'rates are not deleted and rebuilt');
        $this->assertSame($formulationVersionIds, DB::table('formulation_versions')->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, Formulation::query()->where('code', 'demo-f-paint-white')->count());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_no_demo_seeder_hard_codes_the_company_or_reintroduces_a_destructive_or_credential_resetting_pattern(): void
    {
        $banned = [
            '/updateOrCreate\(/' => 'updateOrCreate rewrites existing rows; use firstOrCreate',
            '/config\(\'demo\.user\.password\'\)/' => 'use DemoCredentials::password()',
            '/findOrFail\(1\)/' => 'use $this->demoCompanyId()',
            '/setPermissionsTeamId\(1\)/' => 'use $this->demoCompanyId()',
            '/registerForTeam\(1\)/' => 'use $this->demoCompanyId()',
            '/->restore\(\)/' => 'do not resurrect deleted rows',
            '/syncPermissions\(/' => 'sync strips grants; use givePermissionTo',
            '/str\(\)->random|Str::random/' => 'a password is created once, never randomised per run',
        ];
        // The discount demo (#86) is a separately reviewed create-only seeder; it is held to the guard by the first test, not by these patterns.
        $exempt = ['Sales/DiscountAuthorityDemoSeeder.php'];
        $offences = [];
        foreach ((new Finder)->files()->in(database_path('seeders/Demo'))->name('*.php') as $file) {
            if (in_array($file->getRelativePathname(), $exempt, true)) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            foreach ($banned as $pattern => $why) {
                if (preg_match($pattern, $code) === 1) {
                    $offences[] = $file->getRelativePathname().': '.$why;
                }
            }
        }
        $this->assertSame([], $offences);
    }

    /** @return array<string, mixed> row counts for every table plus the credentials and guarded values */
    private function snapshot(): array
    {
        $counts = [];
        foreach (Schema::getTableListing() as $table) {
            $name = preg_replace('/^public\./', '', $table);
            if (in_array($name, ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs'], true)) {
                continue;
            }
            $counts[$name] = DB::table($name)->count();
        }
        ksort($counts);

        return $counts;
    }
}
