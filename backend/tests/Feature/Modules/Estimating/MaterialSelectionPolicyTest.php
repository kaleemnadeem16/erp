<?php

namespace Tests\Feature\Modules\Estimating;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\ResolveMaterialSelectionStrategies;
use App\Modules\Estimating\Actions\SetMaterialSelectionPolicy;
use App\Modules\Estimating\Models\MaterialSelectionPolicy;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\MaterialSelectionSource;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Modules\Estimating\Support\ResolvedSelectionStrategy;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** ADR 0071: stored selection strategies — item → category ancestry → company → built-in, governed and kept as history. */
class MaterialSelectionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Category $root;

    private Category $child;

    private Item $item;

    private Item $other;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('estimating.selection-policy.manage');
        Permission::findOrCreate('estimating.policy-history.view');
        $kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->root = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->child = Category::create(['code' => 'PIG', 'name' => 'Pigments', 'parent_id' => $this->root->id]);
        $this->item = Item::create(['code' => 'BLUE', 'name' => 'Blue pigment', 'type' => ItemType::Raw, 'stock_uom_id' => $kg->id, 'category_id' => $this->child->id]);
        $this->other = Item::create(['code' => 'SAND', 'name' => 'Sand', 'type' => ItemType::Raw, 'stock_uom_id' => $kg->id, 'category_id' => $this->root->id]);
    }

    private function set(MaterialSelectionScope $scope, ?int $id, ?MaterialSelectionStrategy $strategy, string $reason = 'Because'): MaterialSelectionPolicy
    {
        return app(SetMaterialSelectionPolicy::class)($scope, $id, $strategy, $reason, User::factory()->create()->id);
    }

    private function resolve(int $itemId, ?MaterialSelectionStrategy $override = null): ResolvedSelectionStrategy
    {
        return app(ResolveMaterialSelectionStrategies::class)([$itemId], $override)[$itemId];
    }

    public function test_with_nothing_stored_the_built_in_default_applies(): void
    {
        $r = $this->resolve($this->item->id);

        $this->assertSame(MaterialSelectionStrategy::MinimiseWaste, $r->strategy);
        $this->assertSame(MaterialSelectionSource::BuiltIn, $r->source);
    }

    public function test_the_nearest_level_wins_item_then_category_ancestry_then_company(): void
    {
        $this->set(MaterialSelectionScope::Company, null, MaterialSelectionStrategy::LowestInternalCost);
        $this->assertSame(MaterialSelectionSource::Company, $this->resolve($this->item->id)->source);

        $this->set(MaterialSelectionScope::ItemCategory, $this->root->id, MaterialSelectionStrategy::FewestPackages);
        $viaAncestor = $this->resolve($this->item->id);
        $this->assertSame(MaterialSelectionStrategy::FewestPackages, $viaAncestor->strategy);
        $this->assertSame(MaterialSelectionSource::ItemCategory, $viaAncestor->source);
        $this->assertSame($this->root->id, $viaAncestor->scopeId);

        $this->set(MaterialSelectionScope::ItemCategory, $this->child->id, MaterialSelectionStrategy::MinimiseWaste);
        $this->assertSame($this->child->id, $this->resolve($this->item->id)->scopeId, 'the nearer category beats its ancestor');

        $this->set(MaterialSelectionScope::Item, $this->item->id, MaterialSelectionStrategy::LowestInternalCost);
        $item = $this->resolve($this->item->id);
        $this->assertSame(MaterialSelectionSource::Item, $item->source);
        $this->assertSame(MaterialSelectionStrategy::LowestInternalCost, $item->strategy);

        // A different item under the root category is untouched by the child category and the item setting.
        $sand = $this->resolve($this->other->id);
        $this->assertSame(MaterialSelectionStrategy::FewestPackages, $sand->strategy);
    }

    public function test_a_request_override_beats_every_stored_level_and_stores_nothing(): void
    {
        $this->set(MaterialSelectionScope::Item, $this->item->id, MaterialSelectionStrategy::LowestInternalCost);
        $before = MaterialSelectionPolicy::query()->count();

        $r = $this->resolve($this->item->id, MaterialSelectionStrategy::FewestPackages);

        $this->assertSame(MaterialSelectionSource::RequestOverride, $r->source);
        $this->assertSame(MaterialSelectionStrategy::FewestPackages, $r->strategy);
        $this->assertSame($before, MaterialSelectionPolicy::query()->count());
    }

    public function test_clearing_a_level_falls_through_and_keeps_who_cleared_it_and_why(): void
    {
        $this->set(MaterialSelectionScope::Company, null, MaterialSelectionStrategy::FewestPackages);
        $this->set(MaterialSelectionScope::Item, $this->item->id, MaterialSelectionStrategy::LowestInternalCost);
        $cleared = $this->set(MaterialSelectionScope::Item, $this->item->id, null, 'No longer special');

        $this->assertNull($cleared->strategy);
        $this->assertSame(MaterialSelectionSource::Company, $this->resolve($this->item->id)->source);
        $this->assertSame(3, MaterialSelectionPolicy::query()->count());
        $this->assertSame(1, MaterialSelectionPolicy::query()->where('scope_type', 'ITEM')->where('status', 'SUPERSEDED')->count());
    }

    public function test_a_change_supersedes_the_old_row_and_recording_the_same_value_writes_nothing(): void
    {
        $first = $this->set(MaterialSelectionScope::Company, null, MaterialSelectionStrategy::FewestPackages);
        $same = $this->set(MaterialSelectionScope::Company, null, MaterialSelectionStrategy::FewestPackages, 'Again');
        $this->assertSame($first->id, $same->id);
        $this->assertSame(1, MaterialSelectionPolicy::query()->count());

        $second = $this->set(MaterialSelectionScope::Company, null, MaterialSelectionStrategy::LowestInternalCost);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('SUPERSEDED', $first->refresh()->status->value);
        $this->assertNotNull($first->effective_to);
        $this->assertSame('ACTIVE', $second->status->value);
    }

    public function test_a_reason_is_required_and_bad_scopes_are_refused(): void
    {
        foreach ([
            fn () => $this->set(MaterialSelectionScope::Company, null, MaterialSelectionStrategy::FewestPackages, '  '),
            fn () => $this->set(MaterialSelectionScope::Item, null, MaterialSelectionStrategy::FewestPackages),
            fn () => $this->set(MaterialSelectionScope::Company, $this->item->id, MaterialSelectionStrategy::FewestPackages),
            fn () => $this->set(MaterialSelectionScope::Item, 99999, MaterialSelectionStrategy::FewestPackages),
            fn () => $this->set(MaterialSelectionScope::Item, $this->item->id, null),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected the change to be refused.');
            } catch (DomainInvariantException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, MaterialSelectionPolicy::query()->count());
    }

    public function test_history_rows_cannot_be_edited_or_deleted(): void
    {
        $row = $this->set(MaterialSelectionScope::Company, null, MaterialSelectionStrategy::FewestPackages);

        $this->expectException(DomainInvariantException::class);
        $row->update(['strategy' => MaterialSelectionStrategy::LowestInternalCost]);
    }

    public function test_history_rows_cannot_be_deleted(): void
    {
        $row = $this->set(MaterialSelectionScope::Company, null, MaterialSelectionStrategy::FewestPackages);

        $this->expectException(DomainInvariantException::class);
        $row->delete();
    }

    public function test_the_api_needs_the_manage_permission_to_change_and_history_or_manage_to_read(): void
    {
        $body = ['scope_type' => 'COMPANY', 'strategy' => 'FEWEST_PACKAGES', 'reason' => 'Tins cost time to open'];
        $nobody = User::factory()->create();
        $this->actingAs($nobody)->postJson('/api/v1/material-selection-policies', $body)->assertForbidden();
        $this->actingAs($nobody)->getJson('/api/v1/material-selection-policies')->assertForbidden();

        $manager = User::factory()->create();
        $manager->givePermissionTo('estimating.selection-policy.manage');
        $this->actingAs($manager)->postJson('/api/v1/material-selection-policies', $body)
            ->assertCreated()
            ->assertJsonPath('data.strategy.code', 'FEWEST_PACKAGES')
            ->assertJsonPath('data.scope_name', 'Whole company')
            ->assertJsonPath('data.changed_by_user.id', $manager->id);

        $auditor = User::factory()->create();
        $auditor->givePermissionTo('estimating.policy-history.view');
        $this->actingAs($auditor)->getJson('/api/v1/material-selection-policies?filter[status]=ACTIVE')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($auditor)->postJson('/api/v1/material-selection-policies', $body)->assertForbidden();
    }

    public function test_the_api_validates_the_reason_scope_and_a_missing_strategy(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('estimating.selection-policy.manage');
        $post = fn (array $body) => $this->actingAs($manager)->postJson('/api/v1/material-selection-policies', $body);

        $post(['scope_type' => 'COMPANY', 'strategy' => 'FEWEST_PACKAGES'])->assertJsonValidationErrors('reason');
        $post(['scope_type' => 'COMPANY', 'reason' => 'x'])->assertJsonValidationErrors('strategy');
        $post(['scope_type' => 'COMPANY', 'strategy' => 'CHEAPEST', 'reason' => 'x'])->assertJsonValidationErrors('strategy');
        $post(['scope_type' => 'ITEM', 'strategy' => 'FEWEST_PACKAGES', 'reason' => 'x'])->assertJsonValidationErrors('scope_id');
        $post(['scope_type' => 'COMPANY', 'scope_id' => 1, 'strategy' => 'FEWEST_PACKAGES', 'reason' => 'x'])->assertJsonValidationErrors('scope_id');
        $post(['scope_type' => 'ITEM', 'scope_id' => 99999, 'strategy' => 'FEWEST_PACKAGES', 'reason' => 'x'])->assertUnprocessable();

        $post(['scope_type' => 'ITEM_CATEGORY', 'scope_id' => $this->child->id, 'strategy' => 'LOWEST_INTERNAL_COST', 'reason' => 'x'])
            ->assertCreated()->assertJsonPath('data.scope_name', 'Pigments');
        $post(['scope_type' => 'ITEM_CATEGORY', 'scope_id' => $this->child->id, 'strategy' => null, 'reason' => 'reset'])
            ->assertCreated()->assertJsonPath('data.strategy', null);
    }
}
