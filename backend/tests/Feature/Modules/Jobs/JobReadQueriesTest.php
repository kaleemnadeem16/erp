<?php

namespace Tests\Feature\Modules\Jobs;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Jobs\Queries\JobReadFilter;
use App\Modules\Jobs\Queries\JobReadProjection;
use App\Modules\Jobs\Queries\ReadJobs;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Jobs\Support\JobsException;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\PreviewServiceRelease;
use App\Modules\Sales\Actions\ReleaseHandoverComponent;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Jobs\Concerns\BuildsLifecycleChains;
use Tests\Feature\Modules\Sales\Concerns\BuildsHandoverFixture;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Support\Sales\ScopedReleaseFixtures;
use Tests\TestCase;

/** Internal reads against real PostgreSQL evidence; test-only ACTIVE manifest, no production activation. */
class JobReadQueriesTest extends TestCase
{
    use BuildsHandoverFixture;
    use BuildsIssuableQuotations;
    use BuildsLifecycleChains;
    use RefreshDatabase;
    use ScopedReleaseFixtures;

    private User $user;

    private User $worker;

    private int $otherCompany;

    private int $otherUser;

    private ReadJobs $reads;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->user = User::factory()->create();
        $this->worker = User::factory()->create();
        $this->otherCompany = DB::table('companies')->insertGetId(['name' => 'Other company', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherUser = DB::table('users')->insertGetId(['company_id' => $this->otherCompany, 'name' => 'Foreign worker', 'email' => 'foreign@jobs.invalid', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->activate();
        $this->grant($this->user, 'job.view');
        $this->reads = app(ReadJobs::class);
    }

    private function activate(JobDutyStatus $status = JobDutyStatus::Active): void
    {
        $duties = array_map(fn (JobDuty $duty): JobDuty => in_array($duty->name, ['job.view', 'job.close', 'job.assign', 'job.receive'], true)
            ? new JobDuty($duty->name, $status, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty, JobDutyManifest::shipped()->all());
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest($duties));
    }

    private function grant(User $actor, string ...$duties): void
    {
        foreach ($duties as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $actor->givePermissionTo($duties);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function settle(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function job(string $number = 'JOB-1', string $description = 'Operational survey', int $company = 1): int
    {
        $id = $this->newJob($number, $company);
        DB::table('job_baselines')->insert(['company_id' => $company, 'job_id' => $id, 'version' => 1, 'fingerprint' => 'PRIVATE-BASELINE-HASH',
            'scope_description' => $description, 'completion_rule_version' => 'TEST_V1', 'recorded_at' => now()]);
        DB::table('job_activities')->insert(['company_id' => $company, 'job_id' => $id, 'kind' => 'DEFAULT', 'description' => $description]);
        $this->settle();

        return $id;
    }

    private function denyAll(?User $actor): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach ([fn () => $this->reads->list($actor), fn () => $this->reads->detail($actor, 999999), fn () => $this->reads->history($actor, 999999)] as $call) {
            try {
                $call();
                $this->fail('Every read requires exact current job.view authority.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertStringNotContainsString('service_jobs', implode('\n', $queries), 'no Job data read before authority');
    }

    public function test_company_isolation_in_lists_and_non_disclosing_id_reads_in_both_contexts(): void
    {
        $mine = $this->job();
        $foreign = $this->job('FOREIGN', 'Foreign deliverable', $this->otherCompany);
        $this->assertSame([$mine], array_column($this->reads->list($this->user)->toArray()['data'], 'id'));
        $this->assertSame($mine, $this->reads->detail($this->user, $mine)->toArray()['id']);
        $messages = [];
        foreach ([$foreign, 999999] as $id) {
            foreach (['detail', 'history'] as $method) {
                try {
                    $this->reads->{$method}($this->user, $id);
                    $this->fail('Foreign and absent Jobs are indistinguishable.');
                } catch (JobsException $e) {
                    $this->assertSame('JOB_NOT_FOUND', $e->reasonCode);
                    $messages[] = $e->getMessage();
                }
            }
        }
        $this->assertCount(1, array_unique($messages));
        $company = $this->otherCompany;
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private int $company) {}

            public function id(): int
            {
                return $this->company;
            }
        });
        app(PermissionRegistrar::class)->setPermissionsTeamId($company);
        $actor = User::withoutGlobalScopes()->findOrFail($this->otherUser);
        $this->grant($actor, 'job.view');
        $this->assertSame([$foreign], array_column($this->reads->list($actor)->toArray()['data'], 'id'));
        $this->assertSame($foreign, $this->reads->detail($actor, $foreign)->toArray()['id']);
        $this->denyAll($this->user);
    }

    public function test_missing_suspended_invited_deleted_and_revoked_actors_are_refused(): void
    {
        $this->denyAll(null);
        $this->denyAll(User::factory()->create());
        // Reuse stale hydrated objects: database membership and grants must win on every call.
        foreach (['SUSPENDED', 'INVITED'] as $status) {
            DB::table('users')->where('id', $this->user->id)->update(['status' => $status]);
            $this->denyAll($this->user);
        }
        DB::table('users')->where('id', $this->user->id)->update(['status' => 'ACTIVE']);
        $this->reads->list($this->user);
        $this->user->revokePermissionTo('job.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->denyAll($this->user);
        $this->grant($this->user, 'job.view');
        DB::table('users')->where('id', $this->user->id)->delete();
        $this->denyAll($this->user);
    }

    public function test_specified_staged_close_only_unrelated_and_limited_authority_cannot_read(): void
    {
        foreach ([JobDutyStatus::Specified, JobDutyStatus::Staged] as $status) {
            $this->activate($status);
            $this->denyAll($this->user);
        }
        $this->activate();
        $other = User::factory()->create();
        $this->grant($other, 'job.close', 'job.assign', P::QUOTATION_VIEW);
        $this->denyAll($other);
        foreach (['MY_UNITS', 'OWN'] as $scope) {
            AccessScopeLimit::create(['user_id' => $this->user->id, 'permission' => 'job.view', 'scope' => $scope]);
            $this->denyAll($this->user);
            AccessScopeLimit::query()->where('user_id', $this->user->id)->delete();
        }
        $this->app->instance(JobDutyManifest::class, JobDutyManifest::shipped());
        $this->denyAll($this->user);
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertSame([], $uris->filter(fn ($u) => preg_match('#^api/v1/(service-)?jobs#', $u) === 1)->values()->all());
    }

    public function test_revocation_between_early_check_and_fenced_decision_refuses_before_job_lookup(): void
    {
        foreach (['list', 'detail', 'history'] as $method) {
            $this->grant($this->user, 'job.view');
            $revoked = false;
            $queries = [];
            DB::listen(function ($query) use (&$revoked, &$queries): void {
                $queries[] = $query->sql;
                if (! $revoked && str_contains($query->sql, 'company_access_generations')) {
                    $revoked = true;
                    $this->user->revokePermissionTo('job.view');
                    app(PermissionRegistrar::class)->forgetCachedPermissions();
                }
            });
            try {
                $method === 'list' ? $this->reads->list($this->user) : $this->reads->{$method}($this->user, 999999);
                $this->fail('Decision-time authority must be fresh.');
            } catch (AuthorizationException) {
                $this->assertTrue($revoked);
                $this->assertStringNotContainsString('service_jobs', implode('\n', $queries));
            }
        }
    }

    public function test_pagination_search_filters_and_stable_sort_use_current_evidence(): void
    {
        $a = $this->job('JOB-A', 'Roof survey');
        $b = $this->job('JOB-B', 'Inspect 100%_literal');
        $c = $this->job('JOB-C', 'Roof survey');
        $this->assign($a, $this->worker->id);
        $this->assign($a, $this->user->id);
        $this->assign($c, $this->worker->id);
        $this->start($c);
        $closed = $this->placeHold($b);
        $this->resolveHold($b, $closed['hold']);
        $this->placeHold($a);
        $this->settle();
        DB::table('service_jobs')->whereIn('id', [$a, $b, $c])->update(['created_at' => '2026-11-01T12:00:00Z']);
        $first = $this->reads->list($this->user, new JobReadFilter(perPage: 2, direction: 'asc'));
        $second = $this->reads->list($this->user, new JobReadFilter(page: 2, perPage: 2, direction: 'asc'));
        $this->assertSame(3, $first->total());
        $this->assertSame([$a, $b], array_column($first->toArray()['data'], 'id'));
        $this->assertSame([$c], array_column($second->toArray()['data'], 'id'));
        foreach ([
            [new JobReadFilter(state: 'ACTIVE'), [$c]], [new JobReadFilter(search: 'roof', sort: 'number', direction: 'asc'), [$a, $c]],
            [new JobReadFilter(search: '%_'), [$b]], [new JobReadFilter(search: 'JOB-B'), [$b]],
            [new JobReadFilter(responsibleMemberId: $this->worker->id), [$c]], [new JobReadFilter(hasOpenHold: true), [$a]],
            [new JobReadFilter(hasOpenHold: false, sort: 'number', direction: 'asc'), [$b, $c]],
            [new JobReadFilter(createdFrom: '2026-11-02'), []], [new JobReadFilter(createdUntil: '2026-10-31'), []],
            [new JobReadFilter(createdFrom: '2026-11-01', createdUntil: '2026-11-01', sort: 'number', direction: 'asc'), [$a, $b, $c]],
        ] as [$filter, $expected]) {
            $this->assertSame($expected, array_column($this->reads->list($this->user, $filter)->toArray()['data'], 'id'));
        }
        foreach (['number', 'state', 'created_at', 'released_at', 'started_at'] as $sort) {
            $this->assertCount(3, $this->reads->list($this->user, new JobReadFilter(sort: $sort))->items());
        }
        $this->assertSame([$c, $b, $a], array_column($this->reads->list($this->user, new JobReadFilter(sort: 'started_at'))->toArray()['data'], 'id'), 'dated start first, then NULLs by descending id');
        $this->assertSame([$c, $a, $b], array_column($this->reads->list($this->user, new JobReadFilter(sort: 'state', direction: 'asc'))->toArray()['data'], 'id'));
    }

    public function test_list_executes_bounded_sql_without_loading_company_histories_or_using_n_plus_one_queries(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->job('JOB-'.$i, str_repeat('Long deliverable ', 20));
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $result = $this->reads->list($this->user, new JobReadFilter(perPage: 2));
        $jobQueries = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'service_jobs')));
        $this->assertSame(12, $result->total());
        $this->assertCount(2, $result->items());
        $this->assertCount(2, $jobQueries, 'one SQL count, one SQL page; no query per Job');
        $this->assertStringContainsString('limit 2', $jobQueries[1]);
        $this->assertSame(160, mb_strlen($result->items()[0]->toArray()['description']));
        // Preserve a real query plan as evidence that PostgreSQL plans the bounded query, not PHP.
        $pageQuery = null;
        DB::listen(function ($query) use (&$pageQuery): void {
            if (str_contains($query->sql, 'service_jobs') && str_contains($query->sql, 'limit 2')) {
                $pageQuery = $query;
            }
        });
        $this->reads->list($this->user, new JobReadFilter(perPage: 2));
        $plan = DB::select('EXPLAIN (FORMAT JSON) '.$pageQuery->sql, $pageQuery->bindings);
        $this->assertStringContainsString('Limit', json_encode($plan, JSON_THROW_ON_ERROR));
    }

    public function test_detail_and_history_derive_assignment_holds_and_order_from_immutable_evidence(): void
    {
        $job = $this->job();
        $this->assign($job, $this->worker->id, ['occurred_at' => '2026-12-01T00:00:00Z']);
        $this->assign($job, $this->user->id, ['occurred_at' => '2026-01-01T00:00:00Z']);
        $hold = $this->placeHold($job);
        $this->resolveHold($job, $hold['hold']);
        $this->start($job);
        $open = $this->placeHold($job);
        $this->settle();
        $before = DB::table('job_events')->orderBy('id')->get()->toJson();
        $detail = $this->reads->detail($this->user, $job)->toArray();
        $this->assertSame($this->user->id, $detail['assignment']['member_id']);
        $this->assertSame([$open['hold']], array_column($detail['open_holds'], 'id'));
        $this->assertSame('Operational survey', $detail['baseline']['scope_description']);
        $this->assertSame('Operational survey', $detail['default_activity']['description']);
        $this->assertNotNull($detail['started_at']);
        $this->assertArrayNotHasKey('sales_origin', $detail);
        $history = $this->reads->history($this->user, $job, perPage: 3);
        $this->assertSame(7, $history->total());
        $rows = $history->toArray()['data'];
        $this->assertSame(['RELEASE', 'ASSIGN', 'ASSIGN'], array_column($rows, 'operation'));
        $this->assertSame([1, 2, 3], array_column($rows, 'sequence'));
        $this->assertGreaterThan($rows[2]['claimed_occurred_at'], $rows[1]['claimed_occurred_at']);
        $this->assertSame(['HOLD', 'RESOLVE_HOLD', 'START'], array_column($this->reads->history($this->user, $job, page: 2, perPage: 3)->toArray()['data'], 'operation'));
        $this->assertSame($before, DB::table('job_events')->orderBy('id')->get()->toJson());
        $json = json_encode([$detail, $history], JSON_THROW_ON_ERROR);
        foreach (['fingerprint', 'PRIVATE-BASELINE-HASH', 'semantic_intent_hash', 'command_identity', 'intent_hash', 'unit_price', 'margin', 'cost', 'customer_representative_snapshot', 'evidence_version'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_empty_missing_baseline_and_unsupported_origin_are_safe_and_results_are_detached(): void
    {
        $this->assertSame(0, $this->reads->list($this->user)->total());
        $job = $this->newJob();
        DB::table('job_handover_origins')->insert(['company_id' => 1, 'job_id' => $job, 'source_contract_version' => 'UNSUPPORTED_V1',
            'target_purpose' => 'MAINTENANCE', 'business_identity' => 'private-source-identity', 'intent_hash' => str_repeat('a', 64), 'recorded_at' => now()]);
        $this->settle();
        $dto = $this->reads->detail($this->user, $job);
        $this->assertInstanceOf(JobReadProjection::class, $dto);
        $detail = $dto->toArray();
        $this->assertNull($detail['description']);
        $this->assertNull($detail['baseline']);
        $this->assertNull($detail['assignment']);
        $this->assertNull($detail['default_activity']);
        $this->assertSame([], $detail['open_holds']);
        $this->assertArrayNotHasKey('sales_origin', $detail);
        $this->assertStringNotContainsString('private-source-identity', json_encode($detail, JSON_THROW_ON_ERROR));
        $this->assertSame([], $this->reads->history($this->user, $job, page: 2)->items());
        $this->user->revokePermissionTo('job.view');
        $this->assertSame($detail, $dto->jsonSerialize(), 'materialised DTO has no lazy query or models');
        $this->denyAll($this->user);
    }

    public function test_invalid_filters_and_pagination_cannot_expand_the_projection(): void
    {
        foreach ([['sort' => 'unit_price'], ['sort' => 'created_at; select 1'], ['direction' => 'sideways'], ['state' => 'FAKE'], ['page' => 0], ['perPage' => 101],
            ['createdFrom' => '2026-02-30'], ['createdUntil' => 'yesterday'], ['createdFrom' => '2026-11-02', 'createdUntil' => '2026-11-01'], ['search' => str_repeat('x', 201)]] as $args) {
            try {
                new JobReadFilter(...$args);
                $this->fail('Invalid read input must refuse.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $job = $this->job();
        $this->expectException(InvalidArgumentException::class);
        $this->reads->history($this->user, $job, perPage: 101);
    }

    protected function handoverDuties(): array
    {
        return [P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::QUOTATION_WITHDRAW, P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW,
            P::HANDOVER_PREPARE, P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE];
    }

    public function test_real_sales_origin_is_a_safe_reference_only_under_independent_current_sales_access(): void
    {
        $this->setUpIssuing();
        $this->activateHandover();
        $this->grant($this->checker, ...[...$this->handoverDuties(), 'job.receive']);
        $f = $this->serviceAcceptance($this->checker, $this->customer->id);
        $token = app(PreviewHandover::class)($this->checker, $f['acceptance'], $f['inputs'])['preview_token'];
        app(PrepareHandoverPlan::class)($this->checker, 'read-prepare', $f['acceptance'], $f['inputs'], $token);
        $this->settle();
        $this->recordScopedBasis($this->checker, 'read-basis', $f['acceptance'], $f['case']);
        $stage = (int) DB::table('handover_stages')->value('id');
        $p = app(PreviewServiceRelease::class)($this->checker, $stage);
        app(ReleaseHandoverComponent::class)($this->checker, 'read-release', $stage, (int) $p['basis_id'], $p['fingerprint']);
        $this->settle();
        $job = (int) DB::table('job_sales_origins')->value('job_id');
        $this->assertArrayNotHasKey('sales_origin', $this->reads->detail($this->user, $job)->toArray());
        $this->grant($this->user, P::QUOTATION_VIEW);
        $detail = $this->reads->detail($this->user, $job)->toArray();
        $this->assertSame($f['revision'], $detail['sales_origin']['revision_id']);
        $this->assertSame(['family_id', 'revision_id'], array_keys($detail['sales_origin']));
        $this->assertSame(DB::table('quotation_issued_lines')->where('revision_id', $f['revision'])->value('description'), $detail['baseline']['deliverable']);
        $this->assertStringContainsString('No materials', $detail['baseline']['agreed_conditions']);
        AccessScopeLimit::create(['user_id' => $this->user->id, 'permission' => P::QUOTATION_VIEW, 'scope' => 'OWN']);
        $this->assertArrayNotHasKey('sales_origin', $this->reads->detail($this->user, $job)->toArray(), 'another owners quotation remains inaccessible');
        AccessScopeLimit::query()->where('user_id', $this->user->id)->delete();
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->assertArrayNotHasKey('sales_origin', $this->reads->detail($this->user, $job)->toArray(), 'inactive Sales duty never leaks a reference');
        $json = json_encode($detail, JSON_THROW_ON_ERROR);
        foreach (['unit_price', 'commercial_snapshot', 'operational_snapshot', 'intent_hash', 'condition_identity', 'basis_id', 'acceptance_id', 'customer_representative_snapshot', 'Synthetic buyer'] as $key) {
            $this->assertStringNotContainsString($key, $json);
        }
    }
}
