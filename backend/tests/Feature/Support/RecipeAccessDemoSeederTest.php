<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\Demo\Estimating\RecipeAccessDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * RecipeAccessDemoSeeder claims an administrator-style setup: a company role holding only the recipe capability, given to one persona.
 * Each claim is proved through the real API, as that persona and as one without recipe access, not by reading the rows back.
 */
final class RecipeAccessDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
    }

    private function planner(): User
    {
        return User::query()->where('email', RecipeAccessDemoSeeder::PERSONA_EMAIL)->firstOrFail();
    }

    public function test_the_role_holds_only_the_recipe_capability_and_the_planner_holds_only_the_role(): void
    {
        $role = Role::query()->where('team_id', 1)->where('name', RecipeAccessDemoSeeder::ROLE)->sole();
        $this->assertSame(['estimating.recipe.view'], $role->permissions()->pluck('name')->all());
        $this->assertSame([RecipeAccessDemoSeeder::ROLE], $this->planner()->getRoleNames()->all());
        $this->assertSame([], $this->planner()->getDirectPermissions()->pluck('name')->all());
    }

    public function test_the_planner_reads_recipes_but_cannot_change_them_and_the_buyer_cannot_read_them(): void
    {
        $planner = $this->planner();
        $this->actingAs($planner)->getJson('/api/v1/formulations?include=activeVersion')->assertOk()->assertJsonStructure(['data']);
        $this->actingAs($planner)->getJson('/api/v1/application-rates')->assertOk();
        $this->actingAs($planner)->getJson('/api/v1/assemblies?include=activeVersion.components')->assertOk();
        $this->actingAs($planner)->postJson('/api/v1/formulations', [])->assertForbidden();
        // No cost, rate or margin comes with the recipe capability.
        $this->actingAs($planner)->getJson('/api/v1/resource-rates')->assertOk()->assertJsonMissingPath('data.0.rate');

        $buyer = User::query()->where('email', 'demo-buyer@erp.x1techs.com')->firstOrFail();
        $this->actingAs($buyer)->getJson('/api/v1/formulations')->assertForbidden();
        $this->actingAs($buyer)->getJson('/api/v1/application-rates')->assertForbidden();
    }

    public function test_the_steward_maintains_items_but_cannot_read_recipes(): void
    {
        $steward = User::query()->where('email', RecipeAccessDemoSeeder::STEWARD_EMAIL)->firstOrFail();
        $role = Role::query()->where('team_id', 1)->where('name', RecipeAccessDemoSeeder::STEWARD_ROLE)->sole();
        $this->assertEqualsCanonicalizing(['inventory.category.manage', 'inventory.item.manage'], $role->permissions()->pluck('name')->all());
        $this->assertSame([RecipeAccessDemoSeeder::STEWARD_ROLE], $steward->getRoleNames()->all());

        $this->actingAs($steward)->getJson('/api/v1/items')->assertOk();
        $this->actingAs($steward)->getJson('/api/v1/formulations')->assertForbidden();
        $this->actingAs($steward)->getJson('/api/v1/application-rates')->assertForbidden();
        $this->actingAs($steward)->getJson('/api/v1/assemblies?include=activeVersion.components')->assertOk()
            ->assertJsonMissingPath('data.0.active_version.components');
    }

    public function test_reseeding_neither_duplicates_the_role_nor_changes_the_assignment(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);

        $this->assertSame(1, Role::query()->where('team_id', 1)->where('name', RecipeAccessDemoSeeder::ROLE)->count());
        $this->assertSame([RecipeAccessDemoSeeder::ROLE], $this->planner()->getRoleNames()->all());
        $this->assertSame(1, Role::query()->where('team_id', 1)->where('name', RecipeAccessDemoSeeder::STEWARD_ROLE)->count());
        $steward = User::query()->where('email', RecipeAccessDemoSeeder::STEWARD_EMAIL)->firstOrFail();
        $this->assertSame([RecipeAccessDemoSeeder::STEWARD_ROLE], $steward->getRoleNames()->all());
    }
}
