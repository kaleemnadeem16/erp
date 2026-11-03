<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\Company;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\ListQuotations;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

class QuotationListApiTest extends TestCase
{
    use BuildsQuotationOwnership, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    public function test_staged_and_unauthenticated_callers_are_refused(): void
    {
        $this->getJson('/api/v1/quotations')->assertUnauthorized();
        Sanctum::actingAs($this->preparer);
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->getJson('/api/v1/quotations')->assertForbidden();
    }

    public function test_own_is_filtered_before_count_and_pagination_and_all_sees_all_local(): void
    {
        $one = $this->nativeFamily();
        $two = $this->nativeFamily();
        $other = User::factory()->create();
        $other->givePermissionTo([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE]);
        $hidden = $this->nativeFamily($other);
        $foreignFamily = $this->foreignFamilyId();
        $foreignRevision = DB::table('quotation_revisions')->insertGetId([
            'company_id' => $this->otherCompany, 'family_id' => $foreignFamily, 'currency_id' => Company::findOrFail(1)->base_currency_id,
            'created_by' => $this->otherUser, 'created_at' => now(), 'updated_at' => now(),
        ]);
        AccessScopeLimit::create(['user_id' => $this->preparer->id, 'permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN']);
        Sanctum::actingAs($this->preparer);
        $first = $this->getJson('/api/v1/quotations?per_page=1&page=1&sort=id')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->json('data');
        $second = $this->getJson('/api/v1/quotations?per_page=1&page=2&sort=id')->assertOk()->assertJsonPath('meta.total', 2)->json('data');
        $this->assertSame($one->id, $first[0]['family_id']);
        $this->assertSame($two->id, $second[0]['family_id']);
        $this->assertEqualsCanonicalizing(['revision_id', 'family_id', 'revision_ordinal', 'state', 'recipient_party_id', 'currency_id', 'created_at', 'valid_through_local_date',
            'official_number', 'availability', 'responsible_owner_id'], array_keys($first[0]));
        $this->assertSame($this->preparer->id, $first[0]['responsible_owner_id'], 'the owner is the family\'s current responsible owner');
        $this->getJson('/api/v1/quotations?filter[owner_id]='.$other->id)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/quotations?filter[mine]=true')->assertOk()->assertJsonPath('meta.total', 2);
        Sanctum::actingAs($this->assigner);
        $rows = $this->getJson('/api/v1/quotations?sort=id')->assertOk()->assertJsonPath('meta.total', 3)->json('data');
        $ids = array_column($rows, 'revision_id');
        $this->assertNotContains($foreignRevision, $ids);
        $this->assertContains($hidden->revisions()->firstOrFail()->id, $ids);
    }

    public function test_list_rejects_unknown_fields_and_direct_call_rejects_unapproved_sorts(): void
    {
        Sanctum::actingAs($this->preparer);
        $this->getJson('/api/v1/quotations?sort=rounding_policy_fingerprint')->assertUnprocessable();
        $this->getJson('/api/v1/quotations?filter[secret]=1')->assertUnprocessable();
        $this->assertThrows(fn () => app(ListQuotations::class)($this->preparer, ['sort' => 'rounding_policy_fingerprint']), ValidationException::class);
    }

    public function test_stable_sort_uses_id_to_break_equal_created_at_values(): void
    {
        [$one, $two] = Carbon::withTestNow(now()->startOfSecond(), fn () => [$this->nativeFamily(), $this->nativeFamily()]);
        $ids = [$one->revisions()->firstOrFail()->id, $two->revisions()->firstOrFail()->id];
        $this->assertSame(1, DB::table('quotation_revisions')->whereIn('id', $ids)->distinct()->count('created_at'));
        Sanctum::actingAs($this->assigner);
        $first = $this->getJson('/api/v1/quotations?per_page=1&page=1&sort=-created_at')->assertOk()->json('data.0.revision_id');
        $second = $this->getJson('/api/v1/quotations?per_page=1&page=2&sort=-created_at')->assertOk()->json('data.0.revision_id');
        $this->assertSame($ids, [$first, $second]);
    }

    public function test_a_member_suspended_after_sign_in_lists_nothing(): void
    {
        $this->nativeFamily();
        Sanctum::actingAs($this->preparer);
        $this->getJson('/api/v1/quotations')->assertOk()->assertJsonPath('meta.total', 1);
        // A raw status change (as an administrator's would be): the hydrated actor is stale, the list must still refuse.
        DB::table('users')->where('id', $this->preparer->id)->update(['status' => 'SUSPENDED']);
        $this->getJson('/api/v1/quotations')->assertForbidden();
    }

    public function test_own_list_excludes_unresolved_while_explicit_all_includes_it(): void
    {
        $owned = $this->nativeFamily();
        $legacy = $this->legacyFamily();
        DB::table('quotation_revisions')->insert(['company_id' => 1, 'family_id' => $legacy->id, 'currency_id' => Company::findOrFail(1)->base_currency_id,
            'created_by' => $this->preparer->id, 'created_at' => now(), 'updated_at' => now()]);
        AccessScopeLimit::create(['user_id' => $this->preparer->id, 'permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN']);
        Sanctum::actingAs($this->preparer);
        $this->getJson('/api/v1/quotations')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.family_id', $owned->id);
        Sanctum::actingAs($this->assigner);
        $rows = $this->getJson('/api/v1/quotations')->assertOk()->assertJsonPath('meta.total', 2)->json('data');
        $this->assertContains($legacy->id, array_column($rows, 'family_id'));
    }

    public function test_a_revoked_direct_view_grant_on_a_hydrated_actor_cannot_list(): void
    {
        $this->nativeFamily();
        $this->preparer->load(['permissions', 'roles']);
        $this->assertTrue($this->preparer->can(Duty::QUOTATION_VIEW));
        User::query()->findOrFail($this->preparer->id)->revokePermissionTo(Duty::QUOTATION_VIEW);
        $this->assertTrue($this->preparer->can(Duty::QUOTATION_VIEW), 'the fixture retains a stale permission relation');
        Sanctum::actingAs($this->preparer);
        $this->getJson('/api/v1/quotations')->assertForbidden();
    }

    public function test_prepare_without_view_cannot_preview_or_edit_existing_or_unknown_revisions(): void
    {
        $revision = $this->nativeFamily()->revisions()->firstOrFail()->id;
        $prepareOnly = User::factory()->create();
        $prepareOnly->givePermissionTo(Duty::QUOTATION_PREPARE);
        Sanctum::actingAs($prepareOnly);
        foreach (['preview', 'editable'] as $surface) {
            $existing = $this->getJson("/api/v1/quotations/{$revision}/{$surface}")->assertForbidden()->assertJsonMissingPath('data');
            $unknown = $this->getJson("/api/v1/quotations/999999999/{$surface}")->assertForbidden()->assertJsonMissingPath('data');
            foreach (['message', 'reason_code', 'errors'] as $field) {
                $this->assertSame($existing->json($field), $unknown->json($field));
            }
        }
    }
}
