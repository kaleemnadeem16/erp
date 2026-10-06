<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Estimating;

use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Actions\SaveRole;
use App\Modules\Administration\Actions\SyncMemberAccess;
use App\Support\CompanyContext;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * Recipe visibility is a capability an administrator grants through a role (audit A9, ADR 0086), not a hard-coded job title. This
 * seeder does what an administrator would in Access Administration, through the same Actions: it creates a company role,
 * "Production planner", holding only `estimating.recipe.view`, and gives it to one persona, so the formulation, application-rate and
 * assembly screens can be tried read-only. Other demo personas (the Buyer, the Receiving Clerk) keep no recipe access, which shows the
 * refusal. Create-once: an existing role is never edited, and the persona is given the role only while it holds no access at all.
 */
final class RecipeAccessDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public const ROLE = 'Production planner';

    public const PERSONA_EMAIL = 'demo-planner@erp.x1techs.com';

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        $admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $company = app(CompanyContext::class)->id();

        $role = Role::query()->where('team_id', $company)->where('name', self::ROLE)->first()
            ?? app(SaveRole::class)(null, [
                'name' => self::ROLE,
                'description' => 'Reads formulation recipes, application rates and assembly components, without changing them.',
                'permissions' => ['estimating.recipe.view'],
                'reason' => 'Demo: an example role — a real company builds its own from the registered capabilities.',
            ], $admin);

        $planner = $this->demoMember(self::PERSONA_EMAIL, 'Demo Production Planner');
        if ($planner->roles()->doesntExist() && $planner->permissions()->doesntExist()) {
            app(SyncMemberAccess::class)($planner, [
                'role_ids' => [$role->id],
                'direct_permissions' => [],
                'reason' => 'Demo persona: a production planner who reads recipes.',
            ], $admin);
        }
    }
}
