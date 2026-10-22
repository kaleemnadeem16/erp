<?php

namespace Tests\Feature\Modules\Tax;

use App\Models\User;
use App\Modules\Tax\Actions\ActivateTaxGroup;
use App\Modules\Tax\Actions\CreateTaxComponent;
use App\Modules\Tax\Actions\CreateTaxVersionSuccessor;
use App\Modules\Tax\Actions\DefineTaxGroupGraph;
use App\Modules\Tax\Models\TaxGroupVersion;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxDutyManifest;
use App\Modules\Tax\Support\TaxRefusal;
use App\Modules\Tax\Support\TaxVersionKind;
use App\Support\CompanyLocks\FinancialActivity;
use App\Support\PermissionRegistry;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaxFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $currency;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->actor = User::factory()->create();
        Permission::findOrCreate(TaxDutyManifest::CONFIGURE, 'web');
        $this->actor->givePermissionTo(TaxDutyManifest::CONFIGURE);
        $this->currency = DB::table('currencies')->insertGetId(['code' => 'TAX', 'name' => 'Illustrative', 'symbol' => 'T', 'decimal_places' => 2, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function enable(): void
    {
        $this->app->instance(TaxDutyManifest::class, new TaxDutyManifest([TaxDutyManifest::CONFIGURE => 'ACTIVE']));
    }

    private function generation(): int
    {
        return (int) DB::table('tax_generations')->where('company_id', 1)->value('generation');
    }

    private function makeComponent(string $code): int
    {
        return app(CreateTaxComponent::class)($this->actor, $code, 'component-'.$code, $this->generation());
    }

    private function version(TaxVersionKind $kind, string $code, array $definition, ?int $previous = null, string $from = '2026-01-01', ?string $to = '2027-01-01', ?string $key = null): int
    {
        return app(CreateTaxVersionSuccessor::class)($this->actor, $kind, $code, $previous, new EffectivePeriod($from, $to), $definition, 'Illustrative test configuration', $key ?? $kind->value.'-'.$code.'-'.$from, $this->generation());
    }

    private function rule(string $code, string $base = 'NET', string $method = 'PERCENT', ?int $currency = null): array
    {
        $component = $this->makeComponent($code);
        $cv = $this->version(TaxVersionKind::Component, $code, ['component_id' => $component, 'name' => $code]);
        $rate = $this->version(TaxVersionKind::Rate, $code, ['component_id' => $component] + ($method === 'PERCENT' ? ['percentage' => '10.000000000'] : ['amount' => '2.0000', 'currency_id' => $currency ?? $this->currency]));
        $rule = $this->version(TaxVersionKind::Rule, $code, ['component_id' => $component, 'component_version_id' => $cv, 'rate_version_id' => $rate, 'method' => $method, 'base' => $base, 'dependency_stage' => 'RAW', 'required_mode' => 'ADVANCED', 'nature' => 'GOODS', 'classification' => 'test-goods', 'jurisdiction' => 'illustrative']);

        return ['component_id' => $component, 'rule_version_id' => $rule, 'predecessors' => []];
    }

    private function group(array $members, string $code = 'test'): int
    {
        $id = $this->version(TaxVersionKind::Group, $code, ['required_mode' => 'ADVANCED', 'currency_id' => $this->currency]);
        app(DefineTaxGroupGraph::class)($this->actor, $id, $members, 'graph-'.$code, $this->generation());

        return $id;
    }

    private function activate(int $id, string $key = 'activate'): int
    {
        return app(ActivateTaxGroup::class)($this->actor, $id, 'Reviewed illustrative graph', $key, $this->generation());
    }

    private function dbRefuses(Closure $call, string $message = ''): void
    {
        try {
            DB::transaction($call);
        } catch (QueryException $e) {
            $this->assertSame('23', substr($e->getCode(), 0, 2));
            if ($message !== '') {
                $this->assertStringContainsString($message, $e->getMessage());
            }

            return;
        }
        $this->fail('Database accepted an invalid Tax write.');
    }

    public function test_shipped_duties_remain_unregistered_ungrantable_and_actions_disabled(): void
    {
        $this->assertSame(['tax.configuration.manage' => 'STAGED', 'tax.profile.manage' => 'STAGED'], app(TaxDutyManifest::class)->statuses());
        $this->assertArrayNotHasKey('tax', app(PermissionRegistry::class)->byOwner());
        $this->assertNotContains(TaxDutyManifest::CONFIGURE, app(PermissionRegistry::class)->all());
        $this->assertNotContains(TaxDutyManifest::CONFIGURE, app(PermissionRegistry::class)->automaticGrants());
        $this->assertTrue($this->actor->can(TaxDutyManifest::CONFIGURE));
        $this->assertThrows(fn () => $this->makeComponent('test'), AuthorizationException::class);
        $this->assertThrows(fn () => $this->activate(999), AuthorizationException::class);
        $this->assertThrows(fn () => app(DefineTaxGroupGraph::class)($this->actor, 999, [], 'graph', 0), AuthorizationException::class);
        $this->assertThrows(fn () => $this->version(TaxVersionKind::Group, 'x', []), AuthorizationException::class);
        $this->assertSame(0, DB::table('tax_components')->count());
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('Modules\\Tax', $route->getActionName());
            $this->assertDoesNotMatchRegularExpression('~(^|/)(tax|tax-group-versions)(/|$)~', $route->uri());
        }
    }

    public function test_unguarded_actor_limited_scope_and_foreign_company_are_denied_before_replay(): void
    {
        $this->enable();
        $this->makeComponent('first');
        $stranger = User::factory()->create();
        $this->assertThrows(fn () => app(CreateTaxComponent::class)($stranger, 'first', 'component-first', 0), AuthorizationException::class);
        DB::table('access_scope_limits')->insert(['company_id' => 1, 'user_id' => $this->actor->id, 'permission' => TaxDutyManifest::CONFIGURE, 'scope' => 'MY_UNITS', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertThrows(fn () => $this->makeComponent('first'), AuthorizationException::class);
        $foreign = clone $this->actor;
        $foreign->company_id = 999;
        $this->assertThrows(fn () => app(CreateTaxComponent::class)($foreign, 'first', 'component-first', 0), AuthorizationException::class);
    }

    public function test_successors_are_adjacent_and_immutable_and_retries_preserve_identity(): void
    {
        $this->enable();
        $id = $this->makeComponent('levy');
        $definition = ['component_id' => $id, 'name' => 'Levy'];
        $first = $this->version(TaxVersionKind::Component, 'levy', $definition);
        $second = $this->version(TaxVersionKind::Component, 'levy', $definition, $first, '2027-01-01', null);
        $this->assertNotSame($first, $second);
        $this->assertSame($first, $this->version(TaxVersionKind::Component, 'levy', $definition));
        $this->dbRefuses(fn () => DB::table('tax_component_versions')->where('id', $first)->update(['name' => 'Changed']), 'immutable');
        $this->dbRefuses(fn () => DB::table('tax_component_versions')->where('id', $first)->delete(), 'immutable');
        $this->dbRefuses(fn () => $this->version(TaxVersionKind::Component, 'levy', $definition, $first, '2026-12-01', '2028-01-01'), 'exact bounded predecessor');
        $this->dbRefuses(fn () => $this->version(TaxVersionKind::Component, 'wrong-code', $definition, $first, '2027-01-01', null), 'stable identity');
    }

    public function test_activation_validates_and_freezes_a_named_dependency_graph(): void
    {
        $this->enable();
        $a = $this->rule('a');
        $b = $this->rule('b', 'NET_PLUS_COMPONENTS');
        $b['predecessors'] = [$a['component_id']];
        $id = $this->group([$b, $a]);
        $activation = $this->activate($id);
        $this->assertSame($activation, $this->activate($id));
        $this->dbRefuses(fn () => DB::table('tax_dependency_versions')->insert(['company_id' => 1, 'group_version_id' => $id, 'component_id' => $a['component_id'], 'predecessor_component_id' => $b['component_id']]), 'immutable');
        $this->dbRefuses(fn () => DB::statement('TRUNCATE tax_dependency_versions'), 'immutable');
        $this->assertSame(1, DB::table('tax_group_activations')->count());
    }

    public function test_cycles_missing_predecessors_and_duplicate_component_ids_refuse(): void
    {
        $this->enable();
        $a = $this->rule('a', 'TAX_AMOUNT');
        $b = $this->rule('b', 'TAX_AMOUNT');
        $a['predecessors'] = [$b['component_id']];
        $b['predecessors'] = [$a['component_id']];
        $cycle = $this->group([$a, $b]);
        $this->dbRefuses(fn () => $this->activate($cycle), 'cycle');
        $a['predecessors'] = [999999];
        $this->dbRefuses(fn () => $this->group([$a], 'missing'), 'foreign key');
        $a['predecessors'] = [];
        $this->dbRefuses(fn () => $this->group([$a, $a], 'duplicate'), 'unique');
        $this->assertSame(0, DB::table('tax_group_activations')->count());
    }

    public function test_incompatible_bases_currency_and_effective_period_refuse_activation(): void
    {
        $this->enable();
        $a = $this->rule('a', 'TAX_AMOUNT');
        $this->dbRefuses(fn () => $this->activate($this->group([$a], 'base')), 'incompatible');
        $currency = DB::table('currencies')->insertGetId(['code' => 'TXX', 'name' => 'Illustrative', 'symbol' => 'T', 'decimal_places' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $b = $this->rule('b', 'NET', 'FIXED_LINE', $currency);
        $this->dbRefuses(fn () => $this->activate($this->group([$b], 'currency')), 'incompatible');
        $g = $this->version(TaxVersionKind::Group, 'period', ['required_mode' => 'ADVANCED', 'currency_id' => $this->currency], null, '2026-01-01', '2028-01-01');
        app(DefineTaxGroupGraph::class)($this->actor, $g, [$b], 'period-graph', $this->generation());
        $this->dbRefuses(fn () => $this->activate($g), 'incompatible');
    }

    public function test_generation_and_key_conflicts_do_not_write_partial_versions(): void
    {
        $this->enable();
        $id = $this->makeComponent('a');
        $this->assertSame($id, app(CreateTaxComponent::class)($this->actor, 'a', 'component-a', 0));
        $this->assertThrows(fn () => app(CreateTaxComponent::class)($this->actor, 'b', 'component-a', 0), TaxRefusal::class);
        $this->assertThrows(fn () => app(CreateTaxComponent::class)($this->actor, 'b', 'fresh', 0), TaxRefusal::class);
        $this->assertSame(1, DB::table('tax_components')->count());
    }

    public function test_raw_cross_company_relationships_and_scoped_reads_refuse(): void
    {
        $this->enable();
        $a = $this->rule('a');
        $company = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $this->dbRefuses(fn () => DB::table('tax_group_members')->insert(['company_id' => $company, 'group_version_id' => $this->group([$a]), 'component_id' => $a['component_id'], 'rule_version_id' => $a['rule_version_id']]), 'foreign key');
        $foreignActor = DB::table('users')->insertGetId(['company_id' => $company, 'name' => 'Other', 'email' => 'tax-other@example.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $foreignGroup = DB::table('tax_group_versions')->insertGetId(['company_id' => $company, 'code' => 'foreign', 'version_no' => 1, 'effective_from' => '2026-01-01', 'reason' => 'Test', 'created_by' => $foreignActor, 'required_mode' => 'BASIC', 'currency_id' => $this->currency]);
        $this->assertNull(TaxGroupVersion::query()->find($foreignGroup));
        $this->assertThrows(fn () => $this->activate($foreignGroup), ModelNotFoundException::class);
    }

    public function test_concurrent_raw_activation_and_graph_writers_wait_on_the_same_generation(): void
    {
        $this->enable();
        $member = $this->rule('locked');
        $group = $this->group([$member]);
        $config = config('database.connections.testing');
        $contender = new \PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
        $contender->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $contender->exec("SET lock_timeout = '100ms'");
        // This test's transaction holds the generation row. Each independent connection must wait at the fence,
        // before it can observe or activate a graph being defined in that transaction.
        foreach ([
            "INSERT INTO tax_group_activations(company_id, group_version_id, activated_by, reason) VALUES (1, {$group}, {$this->actor->id}, 'Contender')",
            "INSERT INTO tax_dependency_versions(company_id, group_version_id, component_id, predecessor_component_id) VALUES (1, {$group}, {$member['component_id']}, {$member['component_id']})",
        ] as $statement) {
            try {
                $contender->exec($statement);
                $this->fail('Concurrent writer passed a held Tax generation fence.');
            } catch (\PDOException $e) {
                $this->assertSame('55P03', $e->getCode());
                $this->assertStringContainsString('lock timeout', $e->getMessage());
            }
        }
        $this->activate($group);
        $this->assertSame(1, DB::table('tax_group_activations')->count());
    }

    public function test_raw_invalid_intervals_overlap_and_method_fields_refuse(): void
    {
        $this->enable();
        $member = $this->rule('raw');
        $rate = (array) DB::table('tax_rate_versions')->first();
        unset($rate['id']);
        $rate['code'] = 'invalid';
        $this->dbRefuses(fn () => DB::table('tax_rate_versions')->insert(array_replace($rate, ['effective_to' => $rate['effective_from']])), 'shape');
        $this->dbRefuses(fn () => DB::table('tax_rate_versions')->insert(array_replace($rate, ['percentage' => '-1'])), 'shape');
        $this->dbRefuses(fn () => DB::table('tax_rate_versions')->insert(array_replace($rate, ['percentage' => 'NaN'])), 'shape');
        $this->dbRefuses(fn () => DB::table('tax_rate_versions')->insert(array_replace($rate, ['amount' => '2.0000', 'currency_id' => $this->currency])), 'shape');
        $rate['code'] = 'raw';
        $this->dbRefuses(fn () => DB::table('tax_rate_versions')->insert($rate), 'unique');
        $this->assertNotContains('fixed Tax rates', app(FinancialActivity::class)->existing());
        $this->rule('fixed', 'NET', 'FIXED_LINE');
        $this->assertContains('fixed Tax rates', app(FinancialActivity::class)->existing());
    }

    public function test_empty_groups_and_document_to_line_dependencies_refuse_activation(): void
    {
        $this->enable();
        $empty = $this->group([], 'empty');
        $this->dbRefuses(fn () => $this->activate($empty), 'needs components');
        $fixed = $this->rule('document', 'NET', 'FIXED_DOCUMENT');
        $dependent = $this->rule('dependent', 'TAX_AMOUNT');
        $dependent['predecessors'] = [$fixed['component_id']];
        $group = $this->group([$fixed, $dependent], 'incompatible-scope');
        $this->dbRefuses(fn () => $this->activate($group), 'assessment scope');
    }

    public function test_populated_rollback_refuses_to_erase_tax_evidence(): void
    {
        $this->enable();
        $this->makeComponent('retained');
        $migration = require database_path('migrations/2026_10_21_100000_create_tax_foundation.php');
        $this->assertThrows(fn () => $migration->down(), \RuntimeException::class);
        $this->assertSame(1, DB::table('tax_components')->count());
    }
}
