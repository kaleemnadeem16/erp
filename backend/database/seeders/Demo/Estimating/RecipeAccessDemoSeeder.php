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
 * seeder does what an administrator would in Access Administration, through the same Actions, for two company roles:
 *
 *  - "Production planner" holds only `estimating.recipe.view`, so the formulation, application-rate and assembly screens can be tried
 *    read-only;
 *  - "Master data steward" maintains items and categories and has NO recipe access, so the item screen and the recipe screens can be
 *    seen refusing someone who is otherwise trusted with master data.
 *
 * The other demo personas (the Buyer, the Receiving Clerk) keep no recipe access either. Create-once: an existing role is never
 * edited, and a persona is given its role only while it holds no access at all.
 */
final class RecipeAccessDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public const ROLE = 'Production planner';

    public const PERSONA_EMAIL = 'demo-planner@erp.x1techs.com';

    public const STEWARD_ROLE = 'Master data steward';

    public const STEWARD_EMAIL = 'demo-steward@erp.x1techs.com';

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        $admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();

        $this->persona($admin, self::ROLE, 'Reads formulation recipes, application rates and assembly components, without changing them.',
            ['estimating.recipe.view'], self::PERSONA_EMAIL, 'Demo Production Planner');
        $this->persona($admin, self::STEWARD_ROLE, 'Maintain item and category master data.',
            ['inventory.item.manage', 'inventory.category.manage'], self::STEWARD_EMAIL, 'Demo Master Data Steward');
    }

    /** @param  list<string>  $permissions */
    private function persona(User $admin, string $roleName, string $description, array $permissions, string $email, string $name): void
    {
        $company = app(CompanyContext::class)->id();
        $role = Role::query()->where('team_id', $company)->where('name', $roleName)->first()
            ?? app(SaveRole::class)(null, [
                'name' => $roleName,
                'description' => $description,
                'permissions' => $permissions,
                'reason' => 'Demo: an example role — a real company builds its own from the registered capabilities.',
            ], $admin);

        $member = $this->demoMember($email, $name);
        if ($member->roles()->doesntExist() && $member->permissions()->doesntExist()) {
            app(SyncMemberAccess::class)($member, [
                'role_ids' => [$role->id],
                'direct_permissions' => [],
                'reason' => "Demo persona: {$name}.",
            ], $admin);
        }
    }
}
