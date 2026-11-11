<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Sales\Concerns\BuildsHandoverFixture;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

class HandoverApiTest extends TestCase
{
    use BuildsHandoverFixture;
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        $this->activateHandover();
        foreach ($this->handoverDuties() as $duty) {
            Permission::findOrCreate($duty, 'web');
            $this->checker->givePermissionTo($duty);
        }
        $this->f = $this->serviceAcceptance($this->checker, $this->customer->id);
    }

    private function base(): string
    {
        return '/api/v1/customer-acceptances/'.$this->f['acceptance'];
    }

    private function preview(): array
    {
        return $this->getJson($this->base().'/handover-preview?'.http_build_query($this->f['inputs']))->assertOk()->json('data');
    }

    private function prepare(array $preview, string $key = 'prepare'): TestResponse
    {
        return $this->postJson($this->base().'/handover-plans', $this->f['inputs'] + ['preview_token' => $preview['preview_token']], ['Idempotency-Key' => $key]);
    }

    public function test_end_to_end_preview_prepare_replay_detail_and_real_readiness_without_a_job(): void
    {
        Sanctum::actingAs($this->checker);
        $p = $this->preview();
        $this->assertTrue($p['readiness']['preparation_ready']);
        $this->assertSame('NOT_READY', $p['readiness']['status']);
        $this->assertNull($p['operational']['quantity']);
        $out = $this->prepare($p)->assertCreated()->assertJsonPath('data.replayed', false);
        $id = $out->json('data.plan_id');
        $this->prepare($p)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.plan_id', $id);
        $this->getJson('/api/v1/handover-plans/'.$id)->assertOk()->assertJsonPath('data.release_performed', false)
            ->assertJsonPath('data.operational.contact.name', 'Synthetic delivery contact')->assertJsonMissingPath('data.preview_token')->assertJsonMissingPath('data.intent_digest')->assertJsonMissingPath('data.operational.unit_price');
        $this->getJson($this->base().'/readiness?'.http_build_query($this->f['inputs']))->assertOk()->assertJsonPath('data.preparation_ready', true)->assertJsonPath('data.status', 'NOT_READY');
        $this->assertDatabaseCount('service_jobs', 0);
        $this->assertDatabaseCount('customer_acceptances', 1);
        $this->assertDatabaseCount('handover_plans', 1);
    }

    public function test_prepare_is_staged_unregistered_and_absent_from_preview_and_unauthenticated_access_is_refused(): void
    {
        $this->getJson($this->base().'/handover-preview')->assertUnauthorized();
        Sanctum::actingAs($this->checker);
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->getJson($this->base().'/handover-preview')->assertForbidden();
        $this->postJson($this->base().'/handover-plans', [])->assertForbidden();
        $this->getJson('/api/v1/handover-plans/1')->assertForbidden();
        $this->assertNotContains(P::HANDOVER_PREPARE, SalesDevPreview::activated());
        $this->assertNotContains(P::HANDOVER_PREPARE, P::all());
    }

    public function test_plan_read_reuses_independent_acceptance_view_and_does_not_need_prepare(): void
    {
        Sanctum::actingAs($this->checker);
        $id = $this->prepare($this->preview())->assertCreated()->json('data.plan_id');
        $this->checker->revokePermissionTo(P::HANDOVER_PREPARE);
        $this->getJson('/api/v1/handover-plans/'.$id)->assertOk();
        $this->getJson($this->base().'/handover-preview')->assertForbidden();
        $this->checker->revokePermissionTo(P::QUOTATION_VIEW);
        $this->getJson('/api/v1/handover-plans/'.$id)->assertForbidden();
        $this->getJson('/api/v1/handover-plans/999999')->assertForbidden();
    }

    public function test_limited_prepare_scope_fails_closed_even_with_unlimited_source_view(): void
    {
        Sanctum::actingAs($this->checker);
        AccessScopeLimit::create(['user_id' => $this->checker->id, 'permission' => P::HANDOVER_PREPARE, 'scope' => 'MY_UNITS']);
        $this->getJson($this->base().'/handover-preview')->assertForbidden();
        $this->postJson($this->base().'/handover-plans', [])->assertForbidden();
    }

    public function test_stale_review_key_reuse_and_unsupported_path_have_stable_refusals(): void
    {
        Sanctum::actingAs($this->checker);
        $p = $this->preview();
        $this->getJson($this->base().'/handover-preview?path=GOODS_ORDER')->assertUnprocessable()->assertJsonPath('reason_code', 'HANDOVER_PATH_UNSUPPORTED');
        $this->getJson($this->base().'/readiness?path=UNKNOWN')->assertUnprocessable()->assertJsonPath('reason_code', 'HANDOVER_PATH_UNSUPPORTED');
        $this->postJson($this->base().'/handover-plans', $this->f['inputs'] + ['preview_token' => $p['preview_token'], 'unit_price' => '1.0000'], ['Idempotency-Key' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors('unit_price');
        DB::table('party_contacts')->where('id', $this->f['contact'])->update(['name' => 'Changed contact']);
        $this->prepare($p, 'stale')->assertConflict()->assertJsonPath('reason_code', 'PREVIEW_TOKEN_STALE');
        $p = $this->preview();
        $this->prepare($p, 'good')->assertCreated();
        $this->postJson($this->base().'/handover-plans', array_replace($this->f['inputs'], ['instructions' => 'Changed instructions', 'preview_token' => $p['preview_token']]), ['Idempotency-Key' => 'good'])->assertConflict()->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');
    }

    public function test_current_unavailable_contact_is_not_certified_and_foreign_source_ids_are_not_found(): void
    {
        Sanctum::actingAs($this->checker);
        $this->getJson($this->base().'/handover-preview?location_kind=REMOTE&contact_id=999999')->assertNotFound();
        DB::table('party_contacts')->where('id', $this->f['contact'])->update(['deleted_at' => now()]);
        $p = $this->preview();
        $this->assertFalse($p['readiness']['preparation_ready']);
        $this->prepare($p)->assertUnprocessable()->assertJsonPath('reason_code', 'HANDOVER_NOT_READY');
        $this->assertDatabaseCount('handover_plans', 0);
    }

    public function test_other_company_cannot_probe_real_acceptance_or_plan(): void
    {
        Sanctum::actingAs($this->checker);
        $id = $this->prepare($this->preview())->assertCreated()->json('data.plan_id');
        $company = DB::table('companies')->insertGetId(['name' => 'Synthetic foreign company', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $company) {}

            public function id(): int
            {
                return $this->company;
            }
        });
        app(PermissionRegistrar::class)->setPermissionsTeamId($company);
        $actor = User::factory()->create();
        $actor->givePermissionTo([P::HANDOVER_PREPARE, P::CUSTOMER_DECISION_VIEW, P::QUOTATION_VIEW]);
        Sanctum::actingAs($actor);
        $this->getJson($this->base().'/handover-preview')->assertNotFound();
        $this->postJson($this->base().'/handover-plans', ['preview_token' => 'fake'], ['Idempotency-Key' => 'foreign'])->assertNotFound();
        $this->getJson('/api/v1/handover-plans/'.$id)->assertNotFound();
    }
}
