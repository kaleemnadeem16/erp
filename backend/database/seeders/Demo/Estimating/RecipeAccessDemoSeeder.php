<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Estimating;

use App\Models\User;
use Database\Seeders\Demo\Concerns\CreatesDemoRolePersonas;
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
    use CreatesDemoRolePersonas;
    use EnsuresDemoEnvironment;

    public const ROLE = 'Production planner';

    public const PERSONA_EMAIL = 'demo-planner@erp.x1techs.com';

    public const STEWARD_ROLE = 'Master data steward';

    public const STEWARD_EMAIL = 'demo-steward@erp.x1techs.com';

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        $admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();

        $this->rolePersona($admin, self::ROLE, 'Reads formulation recipes, application rates and assembly components, without changing them.',
            ['estimating.recipe.view'], self::PERSONA_EMAIL, 'Demo Production Planner');
        $this->rolePersona($admin, self::STEWARD_ROLE, 'Maintain item and category master data.',
            ['inventory.item.manage', 'inventory.category.manage'], self::STEWARD_EMAIL, 'Demo Master Data Steward');
    }
}
