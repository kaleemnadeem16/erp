<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\ChangeQuotationAccessUnit;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

class QuotationFamilyAccessApiTest extends TestCase
{
    use BuildsQuotationOwnership, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
        foreach ([Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated([Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE]));
        $this->preparer->givePermissionTo([Duty::QUOTATION_ASSIGN, Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE]);
        $this->colleague->givePermissionTo([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE]);
        foreach ([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE] as $duty) {
            AccessScopeLimit::create(['user_id' => $this->colleague->id, 'permission' => $duty, 'scope' => 'OWN_OR_DELEGATED']);
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function writes(): array
    {
        $common = ['expected_ownership_generation' => 1, 'expected_access_generation' => 0, 'reason' => 'Private API fixture'];

        return [
            'reassign-owner' => $common + ['owner_id' => $this->colleague->id],
            'change-access-unit' => $common + ['access_unit_id' => null],
            'grant-access' => $common + ['recipient_id' => $this->colleague->id, 'level' => 'VIEW'],
            'revoke-access' => $common + ['grant_id' => 99999999],
        ];
    }

    public function test_every_surface_refuses_unauthenticated_and_staged_duties(): void
    {
        $family = $this->nativeFamily();
        $base = '/api/v1/quotation-families/'.$family->id;
        $this->getJson($base.'/access')->assertUnauthorized();
        foreach ($this->writes() as $route => $body) {
            $this->postJson($base.'/'.$route, $body)->assertUnauthorized();
        }
        Sanctum::actingAs($this->preparer);
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->getJson($base.'/access')->assertForbidden();
        foreach ($this->writes() as $route => $body) {
            $this->postJson($base.'/'.$route, $body)->assertForbidden();
        }
        $this->assertSame(0, DB::table('quotation_access_grants')->count());
    }

    public function test_owner_and_unit_transitions_use_real_actions_preserve_creator_and_replay(): void
    {
        $family = $this->nativeFamily();
        $base = '/api/v1/quotation-families/'.$family->id;
        Sanctum::actingAs($this->preparer);
        $body = $this->writes()['reassign-owner'];
        $key = $this->key();
        $this->postJson($base.'/reassign-owner', $body, ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.owner_id', $this->colleague->id)->assertJsonPath('data.ownership_generation', 2);
        $this->postJson($base.'/reassign-owner', $body, ['Idempotency-Key' => $key])->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.replayed', true);
        $unit = $this->unit();
        $body = ['expected_ownership_generation' => 2, 'expected_access_generation' => 0, 'reason' => 'Assign an explicit unit', 'access_unit_id' => $unit];
        $this->postJson($base.'/change-access-unit', $body, ['Idempotency-Key' => $this->key()])->assertOk()->assertJsonPath('data.access_unit_id', $unit)->assertJsonPath('data.ownership_generation', 3)->assertJsonPath('data.access_generation', 1);
        $this->assertSame($this->preparer->id, $family->fresh()->created_by);
        $this->getJson($base.'/access')->assertOk()->assertJsonPath('data.responsible_owner.id', $this->colleague->id)->assertJsonPath('data.access_unit.id', $unit)->assertJsonPath('data.created_by_name', $this->preparer->name)->assertJsonPath('data.ownership_history.0.new_owner_name', $this->colleague->name)->assertJsonPath('data.ownership_history.0.actor_name', $this->preparer->name)->assertJsonPath('data.allowed_actions.assign', true)->assertJsonCount(3, 'data.ownership_history');
        $this->postJson($base.'/change-access-unit', $body, ['Idempotency-Key' => $this->key()])->assertUnprocessable()->assertJsonPath('reason_code', 'QUOTATION_OWNERSHIP_STALE');
    }

    public function test_grant_revoke_replay_and_history_never_project_raw_reasons_or_authority_pins(): void
    {
        $family = $this->nativeFamily();
        $base = '/api/v1/quotation-families/'.$family->id;
        Sanctum::actingAs($this->preparer);
        $body = $this->writes()['grant-access'];
        $secret = 'Private note 927361480.4183';
        $body['reason'] = $secret;
        $key = $this->key();
        $first = $this->postJson($base.'/grant-access', $body, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.access_generation', 1)->assertJsonPath('data.replayed', false);
        $grant = $first->json('data.grant_id');
        $this->assertSame($secret, DB::table('quotation_access_grants')->where('id', $grant)->value('reason'));
        $this->postJson($base.'/grant-access', $body, ['Idempotency-Key' => $key])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $changed = $body;
        $changed['level'] = 'PREPARE';
        $this->postJson($base.'/grant-access', $changed, ['Idempotency-Key' => $key])->assertConflict()->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
        $snapshot = $this->getJson($base.'/access')->assertOk()->assertJsonPath('data.shares.0.status', 'ACTIVE')->assertJsonCount(1, 'data.shares');
        foreach ([$secret, 'issuer_authorization_generation', 'recipient_authorization_generation', 'organisation_generation', 'capability_generation', 'reason'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $snapshot->getContent());
        }
        Sanctum::actingAs($this->colleague);
        $this->getJson($base.'/access')->assertOk()->assertJsonPath('data.record_scope', 'DELEGATED')->assertJsonPath('data.allowed_actions.prepare', false)->assertJsonPath('data.allowed_actions.assign', false)->assertJsonMissingPath('data.shares')->assertJsonMissingPath('data.ownership_history');
        Sanctum::actingAs($this->preparer);
        $revoke = ['expected_ownership_generation' => 1, 'expected_access_generation' => 1, 'grant_id' => $grant, 'reason' => 'Revoke the access'];
        $key = $this->key();
        $this->postJson($base.'/revoke-access', $revoke, ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.access_generation', 2);
        $this->postJson($base.'/revoke-access', $revoke, ['Idempotency-Key' => $key])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->getJson($base.'/access')->assertOk()->assertJsonPath('data.shares.0.status', 'REVOKED');
        Sanctum::actingAs($this->colleague);
        $this->getJson($base.'/access')->assertNotFound();
    }

    public function test_hidden_foreign_and_unknown_families_have_identical_non_disclosing_errors_on_every_surface(): void
    {
        $family = $this->nativeFamily();
        $foreign = $this->foreignFamilyId();
        $this->colleague->givePermissionTo([Duty::QUOTATION_ASSIGN, Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE]);
        Sanctum::actingAs($this->colleague);
        $unknown = $this->getJson('/api/v1/quotation-families/999999999/access')->assertNotFound()->json();
        foreach ([$family->id, $foreign] as $id) {
            $this->assertSame($unknown, $this->getJson('/api/v1/quotation-families/'.$id.'/access')->assertNotFound()->json());
        }
        foreach ($this->writes() as $route => $body) {
            $unknown = $this->postJson('/api/v1/quotation-families/999999999/'.$route, $body, ['Idempotency-Key' => $this->key()])->assertNotFound()->json();
            foreach ([$family->id, $foreign] as $id) {
                $this->assertSame($unknown, $this->postJson('/api/v1/quotation-families/'.$id.'/'.$route, $body, ['Idempotency-Key' => $this->key()])->assertNotFound()->json());
            }
        }
    }

    public function test_unknown_fields_and_missing_keys_are_refused_without_writes(): void
    {
        $family = $this->nativeFamily();
        Sanctum::actingAs($this->preparer);
        $base = '/api/v1/quotation-families/'.$family->id;
        $this->getJson($base.'/access?include=authority_epochs')->assertUnprocessable();
        $body = $this->writes()['grant-access'];
        $this->postJson($base.'/grant-access', $body + ['allow' => true], ['Idempotency-Key' => $this->key()])->assertUnprocessable()->assertJsonValidationErrors('allow');
        $this->postJson($base.'/grant-access', $body)->assertUnprocessable()->assertJsonPath('reason_code', 'REQUEST_KEY_REQUIRED');
        $this->assertSame(0, DB::table('quotation_access_grants')->count());
        $this->assertSame(0, $family->fresh()->access_generation);
    }

    public function test_candidates_are_guarded_minimal_active_company_identities_with_bounded_cursor_pages(): void
    {
        $family = $this->nativeFamily();
        $base = '/api/v1/quotation-families/'.$family->id.'/access-candidates';
        $this->getJson($base.'?kind=owner')->assertUnauthorized();
        Sanctum::actingAs($this->preparer);
        $first = $this->getJson($base.'?kind=owner&per_page=2')->assertOk();
        $rows = $first->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame(['id', 'name'], array_keys($rows[0]));
        $next = $this->getJson($first->json('links.next'))->assertOk()->json('data');
        $this->assertNotEmpty($next);
        $this->assertSame([], array_intersect(array_column($rows, 'id'), array_column($next, 'id')));
        $all = $this->getJson($base.'?kind=owner&per_page=50')->assertOk()->json('data');
        $this->assertNotContains($this->otherUser, array_column($all, 'id'));
        $this->assertNotContains($this->leaver->id, array_column($all, 'id'));
        $recipient = $this->getJson($base.'?kind=recipient&per_page=50')->assertOk()->json('data');
        $this->assertNotContains($this->preparer->id, array_column($recipient, 'id'));
        $active = $this->unit('CANDIDATE-ACTIVE');
        $archived = $this->unit('CANDIDATE-ARCHIVED', 'ARCHIVED');
        $foreign = $this->unit('CANDIDATE-FOREIGN', 'ACTIVE', $this->otherCompany);
        $units = $this->getJson($base.'?kind=unit&per_page=50')->assertOk()->json('data');
        $this->assertSame([$active], array_column($units, 'id'));
        $this->assertNotContains($archived, array_column($units, 'id'));
        $this->assertNotContains($foreign, array_column($units, 'id'));
        $this->getJson($base.'?kind=owner&per_page=51')->assertUnprocessable();
        $this->getJson($base.'?kind=owner&include=permissions')->assertUnprocessable();
        $this->getJson($base.'?kind=owner&filter[authorization_generation]=1')->assertUnprocessable();
        $this->getJson($base.'?kind=owner&cursor=invalid')->assertUnprocessable();
        foreach (['[]', 'null', '{"id":[1],"_pointsToNextItems":true}', '{"id":1,"_pointsToNextItems":"yes"}', '{"id":-1,"_pointsToNextItems":true}', '{"id":1}'] as $malformed) {
            $this->getJson($base.'?kind=owner&cursor='.rawurlencode(base64_encode($malformed)))->assertUnprocessable();
        }
        Sanctum::actingAs($this->viewOnly);
        $this->getJson($base.'?kind=owner')->assertForbidden();
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        Sanctum::actingAs($this->preparer);
        $this->getJson($base.'?kind=owner')->assertForbidden();
    }

    public function test_histories_use_independent_generation_cursors_and_reject_unprivileged_history_queries(): void
    {
        $family = $this->nativeFamily();
        Sanctum::actingAs($this->preparer);
        $base = '/api/v1/quotation-families/'.$family->id;
        $unit = $this->unit();
        $this->postJson($base.'/change-access-unit', ['access_unit_id' => $unit, 'expected_ownership_generation' => 1, 'expected_access_generation' => 0, 'reason' => 'Unit history first'], ['Idempotency-Key' => $this->key()])->assertOk();
        $this->postJson($base.'/change-access-unit', ['access_unit_id' => null, 'expected_ownership_generation' => 2, 'expected_access_generation' => 1, 'reason' => 'Unit history second'], ['Idempotency-Key' => $this->key()])->assertOk();
        $one = $this->postJson($base.'/grant-access', ['recipient_id' => $this->colleague->id, 'level' => 'VIEW', 'expected_ownership_generation' => 3, 'expected_access_generation' => 2, 'reason' => 'First scheduled interval', 'starts_at' => '2080-01-01T00:00:00Z', 'ends_at' => '2081-01-01T00:00:00Z'], ['Idempotency-Key' => $this->key()])->assertCreated()->json('data.grant_id');
        $two = $this->postJson($base.'/grant-access', ['recipient_id' => $this->colleague->id, 'level' => 'VIEW', 'expected_ownership_generation' => 3, 'expected_access_generation' => 3, 'reason' => 'Second scheduled interval', 'starts_at' => '2090-01-01T00:00:00Z'], ['Idempotency-Key' => $this->key()])->assertCreated()->json('data.grant_id');
        $first = $this->getJson($base.'/access?history_per_page=1')->assertOk()->assertJsonPath('data.ownership_history.0.generation', 3)->assertJsonPath('data.ownership_history_next_generation', 3)->assertJsonPath('data.shares.0.id', $two)->assertJsonPath('data.shares.0.status', 'SCHEDULED')->assertJsonPath('data.shares_next_generation', 4)->json('data');
        $this->getJson($base.'/access?history_per_page=1&ownership_before='.$first['ownership_history_next_generation'].'&shares_before='.$first['shares_next_generation'])->assertOk()->assertJsonPath('data.ownership_history.0.generation', 2)->assertJsonPath('data.shares.0.id', $one)->assertJsonPath('data.shares_has_more', false)->assertJsonPath('data.shares_next_generation', null);
        $this->getJson($base.'/access?history_per_page=51')->assertUnprocessable();
        Sanctum::actingAs($this->viewOnly);
        $this->getJson($base.'/access?ownership_before=3')->assertForbidden()->assertJsonMissingPath('data');
    }

    public function test_candidate_query_count_does_not_grow_with_the_number_of_returned_people(): void
    {
        $family = $this->nativeFamily();
        Sanctum::actingAs($this->preparer);
        $base = '/api/v1/quotation-families/'.$family->id.'/access-candidates?kind=owner';
        $this->getJson($base.'&per_page=50')->assertOk();
        $counts = [];
        foreach ([1, 50] as $size) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->getJson($base.'&per_page='.$size)->assertOk();
                $counts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }
        $this->assertSame($counts[0], $counts[1], 'Directory pagination must not perform per-person authority or profile queries.');
    }

    public function test_unit_governor_api_stays_within_the_actual_unit_and_preserves_responsibility_vs_creation(): void
    {
        $inside = $this->nativeFamily();
        $outside = $this->nativeFamily();
        $unit = $this->unit();
        $otherUnit = $this->unit();
        app(ChangeQuotationAccessUnit::class)($this->preparer, $inside->id, $unit, 1, 0, $this->key(), 'Inside unit fixture');
        app(ChangeQuotationAccessUnit::class)($this->preparer, $outside->id, $otherUnit, 1, 0, $this->key(), 'Outside unit fixture');
        DB::table('organisation_unit_members')->insert(['company_id' => 1, 'unit_id' => $unit, 'user_id' => $this->assigner->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([Duty::QUOTATION_VIEW, Duty::QUOTATION_ASSIGN] as $duty) {
            AccessScopeLimit::create(['user_id' => $this->assigner->id, 'permission' => $duty, 'scope' => 'MY_UNITS']);
        }
        Sanctum::actingAs($this->assigner);
        $this->getJson('/api/v1/quotation-families/'.$inside->id.'/access')->assertOk()->assertJsonPath('data.record_scope', 'MY_UNITS')->assertJsonPath('data.allowed_actions.assign', true);
        $this->getJson('/api/v1/quotation-families/'.$outside->id.'/access')->assertNotFound();
        $body = ['owner_id' => $this->colleague->id, 'expected_ownership_generation' => 2, 'expected_access_generation' => 1, 'reason' => 'Unit governor transition'];
        $this->postJson('/api/v1/quotation-families/'.$outside->id.'/reassign-owner', $body, ['Idempotency-Key' => $this->key()])->assertNotFound();
        $this->postJson('/api/v1/quotation-families/'.$inside->id.'/reassign-owner', $body, ['Idempotency-Key' => $this->key()])->assertOk();
        $this->getJson('/api/v1/quotation-families/'.$inside->id.'/access')->assertOk()->assertJsonPath('data.responsible_owner.id', $this->colleague->id)->assertJsonPath('data.created_by', $this->preparer->id);
        $this->assertSame($this->preparer->id, $outside->fresh()->responsible_owner_id);
    }

    public function test_unsupported_or_normalised_invalid_share_instants_are_refused_before_storage(): void
    {
        $family = $this->nativeFamily();
        Sanctum::actingAs($this->preparer);
        $base = '/api/v1/quotation-families/'.$family->id.'/grant-access';
        foreach (['0000-01-01T00:00:00Z', '2026-02-31T00:00:00Z', '2026-01-01T00:00:00+99:99', '0001-01-01T00:00:00+23:59', '9999-12-31T23:59:59-23:59', 'tomorrow'] as $instant) {
            $body = $this->writes()['grant-access'] + ['starts_at' => $instant];
            $this->postJson($base, $body, ['Idempotency-Key' => $this->key()])->assertUnprocessable()->assertJsonPath('reason_code', 'INVALID_INPUT');
        }
        $this->assertSame(0, DB::table('quotation_access_grants')->count());
        $this->assertSame(0, $family->fresh()->access_generation);
    }
}
