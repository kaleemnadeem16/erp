<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\ChangeQuotationAccessUnit;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationFamilyScopeAdapter;
use App\Support\Scope\RecordScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class QuotationTeamAccessTest extends TestCase
{
    use BuildsQuotationOwnership, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    /** @param list<int> $units */
    private function manager(array $units): User
    {
        $actor = PermissionedActor::grant(User::factory()->create(), [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE]);
        foreach ([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE] as $duty) {
            AccessScopeLimit::create(['user_id' => $actor->id, 'permission' => $duty, 'scope' => AccessScopeLimit::MY_UNITS]);
        }
        foreach ($units as $unit) {
            DB::table('organisation_unit_members')->insert(['company_id' => 1, 'unit_id' => $unit, 'user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $actor;
    }

    private function familyInUnit(?int $unit, ?User $owner = null): QuotationFamily
    {
        $family = $this->nativeFamily($owner);
        if ($unit !== null) {
            app(ChangeQuotationAccessUnit::class)($this->assigner, (int) $family->id, $unit, (int) $family->ownership_generation, (int) $family->access_generation, $this->key(), 'Synthetic unit access fixture');
        }

        return $family->refresh();
    }

    /** @param list<string> $duties
     * @return list<int>
     */
    private function visible(User $actor, array $duties = [Duty::QUOTATION_VIEW]): array
    {
        return app(QuotationAccess::class)->queryFor($actor, $duties)->orderBy('id')->pluck('id')->all();
    }

    public function test_parent_reaches_active_descendants_but_leaf_never_reaches_parent_or_sibling(): void
    {
        $hq = $this->unit('hq');
        $main = $this->unit('main');
        $khi = $this->unit('khi');
        DB::table('organisation_units')->whereIn('id', [$main, $khi])->update(['parent_id' => $hq]);
        $a = $this->familyInUnit($hq);
        $b = $this->familyInUnit($main);
        $c = $this->familyInUnit($khi);
        $this->familyInUnit(null);
        $this->legacyFamily();
        $this->foreignFamilyId();
        $parent = $this->manager([$hq]);
        $leaf = $this->manager([$khi]);
        $this->assertSame([$a->id, $b->id, $c->id], $this->visible($parent));
        $this->assertSame([$c->id], $this->visible($leaf));
        Sanctum::actingAs($parent);
        $this->getJson('/api/v1/quotations?per_page=1&sort=id')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data');
        Sanctum::actingAs($leaf);
        $this->getJson('/api/v1/quotations?per_page=1&sort=id')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.family_id', $c->id);
    }

    public function test_hidden_and_unknown_revisions_have_the_same_non_disclosing_response(): void
    {
        config(['app.debug' => false]);
        $main = $this->unit('main');
        $khi = $this->unit('khi');
        $hidden = $this->familyInUnit($khi)->revisions()->firstOrFail();
        Sanctum::actingAs($this->manager([$main]));
        $one = $this->getJson('/api/v1/quotations/'.$hidden->id)->assertNotFound()->assertJsonMissingPath('data')->json();
        $two = $this->getJson('/api/v1/quotations/999999999')->assertNotFound()->assertJsonMissingPath('data')->json();
        $this->assertSame($one, $two);
        $foreign = DB::table('quotation_revisions')->insertGetId(['company_id' => $this->otherCompany, 'family_id' => $this->foreignFamilyId(),
            'currency_id' => $hidden->currency_id, 'created_by' => $this->otherUser, 'created_at' => now(), 'updated_at' => now()]);
        $three = $this->getJson('/api/v1/quotations/'.$foreign)->assertNotFound()->assertJsonMissingPath('data')->json();
        $this->assertSame($one, $three);
    }

    public function test_team_preparation_uses_both_duties_and_direct_actions_cannot_write_outside_the_unit(): void
    {
        $main = $this->unit('main');
        $khi = $this->unit('khi');
        $allowed = $this->familyInUnit($main)->revisions()->firstOrFail();
        $hidden = $this->familyInUnit($khi)->revisions()->firstOrFail();
        $actor = $this->manager([$main]);
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/quotations/'.$allowed->id.'/editable')->assertOk();
        app(UpdateQuotationDraft::class)($actor, (int) $allowed->id, (int) $allowed->input_generation, $this->key(), ['tax_estimate_date' => $this->today]);
        $this->assertGreaterThan($allowed->input_generation, $allowed->fresh()->input_generation);
        $receipts = DB::table('sales_quotation_operation_receipts')->count();
        try {
            app(UpdateQuotationDraft::class)($actor, (int) $hidden->id, (int) $hidden->input_generation, $this->key(), ['tax_estimate_date' => $this->today]);
            $this->fail('A team preparer must not write outside their unit.');
        } catch (DomainInvariantException $refusal) {
            $this->assertSame('QUOTATION_NOT_FOUND', $refusal->reasonCode);
        }
        $this->assertSame($receipts, DB::table('sales_quotation_operation_receipts')->count());
        $this->assertSame($hidden->input_generation, $hidden->fresh()->input_generation);
    }

    public function test_membership_removal_invalidates_a_hydrated_manager_and_no_unit_stays_denied(): void
    {
        $unit = $this->unit();
        $family = $this->familyInUnit($unit);
        $actor = $this->manager([$unit]);
        $this->assertSame([$family->id], $this->visible($actor));
        DB::table('organisation_unit_members')->where('user_id', $actor->id)->delete();
        $this->assertThrows(fn () => $this->visible($actor), AuthorizationException::class);
        $this->assertThrows(fn () => $this->visible($this->manager([])), AuthorizationException::class);
    }

    public function test_archived_descendants_are_not_reached_and_null_units_are_not_inferred_from_owners(): void
    {
        $parent = $this->unit('parent');
        $child = $this->unit('child');
        DB::table('organisation_units')->where('id', $child)->update(['parent_id' => $parent]);
        $actor = $this->manager([$parent]);
        $family = $this->familyInUnit($child);
        $this->familyInUnit(null);
        DB::table('organisation_unit_members')->insert(['company_id' => 1, 'unit_id' => $parent, 'user_id' => $this->preparer->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([$family->id], $this->visible($actor));
        DB::table('organisation_units')->where('id', $child)->update(['status' => 'ARCHIVED']);
        $this->assertSame([], $this->visible($actor));
    }

    public function test_same_duty_role_own_and_direct_units_form_a_union_and_each_required_duty_is_intersected(): void
    {
        $unit = $this->unit();
        $actor = $this->manager([$unit]);
        $role = Role::create(['team_id' => 1, 'name' => 'Quotation owner view']);
        $role->givePermissionTo(Duty::QUOTATION_VIEW);
        $actor->assignRole($role);
        AccessScopeLimit::create(['user_id' => $actor->id, 'role_id' => $role->id, 'scope' => AccessScopeLimit::OWN]);
        AccessScopeLimit::where('user_id', $actor->id)->where('permission', Duty::QUOTATION_PREPARE)->update(['scope' => AccessScopeLimit::OWN]);
        $owned = $this->familyInUnit(null, $actor);
        $team = $this->familyInUnit($unit);
        $this->assertSame([$owned->id, $team->id], $this->visible($actor));
        $this->assertSame([$owned->id], $this->visible($actor, [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE]));
        $generic = app(RecordScope::class)->resolve($actor, Duty::QUOTATION_VIEW);
        $this->assertTrue($generic->isNone(), 'Legacy adapters must still refuse mixed OWN/unit variants.');
    }

    public function test_own_member_does_not_gain_team_visibility_and_unrestricted_path_still_wins(): void
    {
        $unit = $this->unit();
        $owned = $this->familyInUnit($unit);
        $other = $this->familyInUnit($unit, $this->assignerWithPrepare());
        DB::table('organisation_unit_members')->insert(['company_id' => 1, 'unit_id' => $unit, 'user_id' => $this->preparer->id, 'created_at' => now(), 'updated_at' => now()]);
        AccessScopeLimit::create(['user_id' => $this->preparer->id, 'permission' => Duty::QUOTATION_VIEW, 'scope' => AccessScopeLimit::OWN]);
        $this->assertSame([$owned->id], $this->visible($this->preparer));
        $actor = $this->manager([$unit]);
        $role = Role::create(['team_id' => 1, 'name' => 'Explicit quotation company view']);
        $role->givePermissionTo(Duty::QUOTATION_VIEW);
        $actor->assignRole($role);
        $legacy = $this->legacyFamily();
        $this->assertSame([$owned->id, $other->id, $legacy->id], $this->visible($actor));
        $this->assertTrue(app(RecordScope::class)->resolveForResource($actor, Duty::QUOTATION_VIEW, QuotationFamilyScopeAdapter::RESOURCE)->isAll());
    }

    public function test_unit_membership_or_a_manager_title_without_the_capability_never_grants_access(): void
    {
        $unit = $this->unit();
        $this->familyInUnit($unit);
        $actor = User::factory()->create(['name' => 'Sales Manager']);
        DB::table('organisation_unit_members')->insert(['company_id' => 1, 'unit_id' => $unit, 'user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertThrows(fn () => $this->visible($actor), AuthorizationException::class);
    }

    private function assignerWithPrepare(): User
    {
        $this->assigner->givePermissionTo(Duty::QUOTATION_PREPARE);

        return $this->assigner;
    }
}
