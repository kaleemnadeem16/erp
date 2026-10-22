<?php

namespace Tests\Feature\Modules\Tax;

use App\Models\User;
use App\Modules\Parties\Http\Resources\PartyResource;
use App\Modules\Parties\Models\Party;
use App\Modules\Tax\Actions\ActivateTaxPartyProfile;
use App\Modules\Tax\Actions\CreateTaxComponent;
use App\Modules\Tax\Actions\CreateTaxPartyProfileSuccessor;
use App\Modules\Tax\Models\TaxPartyComponentEvidence;
use App\Modules\Tax\Models\TaxPartyProfileActivation;
use App\Modules\Tax\Models\TaxPartyProfileVersion;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxDutyManifest;
use App\Modules\Tax\Support\TaxRefusal;
use App\Support\PermissionRegistry;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaxPartyProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Party $party;

    private int $component;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->actor = User::factory()->create();
        foreach ([TaxDutyManifest::CONFIGURE, TaxDutyManifest::PROFILE] as $duty) {
            Permission::findOrCreate($duty, 'web');
            $this->actor->givePermissionTo($duty);
        }
        $this->party = Party::create(['code' => 'prospect', 'name' => 'Prospect']);
        $this->app->instance(TaxDutyManifest::class, new TaxDutyManifest([TaxDutyManifest::CONFIGURE => 'ACTIVE']));
        $this->component = app(CreateTaxComponent::class)($this->actor, 'illustrative', 'component', 0);
        $this->app->instance(TaxDutyManifest::class, new TaxDutyManifest);
    }

    private function enable(): void
    {
        $this->app->instance(TaxDutyManifest::class, new TaxDutyManifest([TaxDutyManifest::PROFILE => 'ACTIVE']));
    }

    private function generation(): int
    {
        return (int) DB::table('tax_generations')->where('company_id', 1)->value('generation');
    }

    private function evidence(): array
    {
        return ['component_id' => $this->component, 'disposition' => 'EXEMPT', 'grounds' => 'protected-grounds-723', 'evidence_reference' => 'text-reference-723', 'issuer' => 'protected-issuer-723', 'issued_on' => '2025-01-01', 'expires_on' => '2028-01-01'];
    }

    private function create(?int $previous = null, string $from = '2026-01-01', ?string $to = '2027-01-01', string $key = 'profile', ?array $components = null, ?int $party = null): int
    {
        return app(CreateTaxPartyProfileSuccessor::class)($this->actor, $party ?? $this->party->id, 'illustrative', $previous, new EffectivePeriod($from, $to), 'protected-jurisdiction-723', 'protected-registration-723', $components ?? [$this->evidence()], 'protected-reason-723', $key, $this->generation());
    }

    private function activate(int $profile, string $key = 'activate'): int
    {
        return app(ActivateTaxPartyProfile::class)($this->actor, $profile, 'protected-activation-723', $key, $this->generation());
    }

    private function dbRefuses(Closure $call): void
    {
        try {
            DB::transaction($call);
        } catch (QueryException $e) {
            $this->assertSame('23', substr($e->getCode(), 0, 2));

            return;
        }
        $this->fail('Database accepted invalid Tax profile evidence.');
    }

    public function test_staged_profile_duty_is_unregistered_and_handmade_grants_do_not_enable_it(): void
    {
        $registry = app(PermissionRegistry::class);
        $this->assertSame('STAGED', app(TaxDutyManifest::class)->statuses()[TaxDutyManifest::PROFILE]);
        $this->assertNotContains(TaxDutyManifest::PROFILE, $registry->all());
        $this->assertNotContains('tax.profile.view', $registry->all());
        $this->assertNotContains(TaxDutyManifest::PROFILE, $registry->automaticGrants());
        $this->assertThrows(fn () => $this->create(), AuthorizationException::class);
        $this->assertThrows(fn () => $this->activate(123), AuthorizationException::class);
        $this->assertDatabaseCount('tax_party_profile_versions', 0);
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('Modules\\Tax', $route->getActionName());
        }
    }

    public function test_successor_activation_and_retry_preserve_historical_profile_and_prospect_lifecycle(): void
    {
        $this->enable();
        $before = $this->party->getAttributes();
        $first = $this->create();
        $activation = $this->activate($first);
        $second = $this->create($first, '2027-01-01', '2028-01-01', 'successor', [['component_id' => $this->component, 'disposition' => 'UNKNOWN']]);
        $this->assertSame($first, $this->create());
        $this->assertSame($activation, $this->activate($first));
        $this->assertSame($first, (int) TaxPartyProfileVersion::query()->findOrFail($second)->getAttribute('predecessor_id'));
        $this->assertDatabaseHas('tax_party_component_evidence', ['profile_version_id' => $first, 'disposition' => 'EXEMPT']);
        $this->assertDatabaseHas('tax_party_component_evidence', ['profile_version_id' => $second, 'disposition' => 'UNKNOWN', 'grounds' => null]);
        $this->assertSame($before, $this->party->fresh()->getAttributes());
        $this->assertDatabaseCount('customer_accounts', 0);
        $this->assertDatabaseCount('supplier_accounts', 0);
        $this->assertSame(1, DB::table('tax_party_profile_activations')->count());
    }

    public function test_all_dispositions_are_distinct_and_no_component_or_missing_registration_means_no_inferred_exemption(): void
    {
        $this->enable();
        $components = [];
        foreach (['EXEMPT', 'ZERO_RATED', 'NOT_APPLICABLE', 'UNKNOWN', 'TAXABLE'] as $index => $disposition) {
            $id = DB::table('tax_components')->insertGetId(['company_id' => 1, 'code' => 'disposition-'.$index]);
            $components[] = array_replace($this->evidence(), ['component_id' => $id, 'disposition' => $disposition]);
        }
        $profile = $this->create(components: $components);
        $this->activate($profile);
        $this->assertEqualsCanonicalizing(['EXEMPT', 'ZERO_RATED', 'NOT_APPLICABLE', 'UNKNOWN', 'TAXABLE'], DB::table('tax_party_component_evidence')->where('profile_version_id', $profile)->pluck('disposition')->all());
        $empty = $this->create($profile, '2027-01-01', null, 'unknown-profile', []);
        $this->activate($empty, 'activate-unknown');
        $this->assertSame(0, DB::table('tax_party_component_evidence')->where('profile_version_id', $empty)->count());
    }

    public function test_denied_limited_foreign_and_configuration_only_actors_cannot_probe_or_replay_profiles(): void
    {
        $this->enable();
        $profile = $this->create();
        $this->activate($profile);
        $this->actor->revokePermissionTo(TaxDutyManifest::PROFILE);
        $this->assertThrows(fn () => $this->create(), AuthorizationException::class);
        $this->assertThrows(fn () => $this->activate($profile), AuthorizationException::class);
        $this->actor->givePermissionTo(TaxDutyManifest::PROFILE);
        DB::table('access_scope_limits')->insert(['company_id' => 1, 'user_id' => $this->actor->id, 'permission' => TaxDutyManifest::PROFILE, 'scope' => 'MY_UNITS', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertThrows(fn () => $this->create(party: 99999), AuthorizationException::class);
        $this->assertThrows(fn () => $this->activate(99999), AuthorizationException::class);
        $this->actor->company_id = 999;
        $this->assertThrows(fn () => $this->create(), AuthorizationException::class);
    }

    public function test_generation_conflicts_key_conflicts_and_invalid_final_child_roll_back_atomically(): void
    {
        $this->enable();
        $before = $this->generation();
        $profile = $this->create();
        $this->assertThrows(fn () => app(ActivateTaxPartyProfile::class)($this->actor, $profile, 'review', 'stale', $before), TaxRefusal::class);
        $this->assertThrows(fn () => $this->create(components: []), TaxRefusal::class);
        $rows = DB::table('tax_operation_receipts')->count();
        $generation = $this->generation();
        $this->assertThrows(fn () => $this->create($profile, '2027-01-01', '2028-01-01', 'bad-successor', [$this->evidence(), $this->evidence()]), TaxRefusal::class);
        $this->assertDatabaseCount('tax_party_profile_versions', 1);
        $this->assertDatabaseCount('tax_party_component_evidence', 1);
        $this->assertSame($rows, DB::table('tax_operation_receipts')->count());
        $this->assertSame($generation, $this->generation());
    }

    public function test_raw_evidence_cannot_be_changed_deleted_truncated_or_extended_after_activation(): void
    {
        $this->enable();
        $profile = $this->create();
        $this->activate($profile);
        foreach (['tax_party_profile_versions', 'tax_party_component_evidence', 'tax_party_profile_activations'] as $table) {
            $this->dbRefuses(fn () => DB::table($table)->update(['reason' => 'rewrite']));
            $this->dbRefuses(fn () => DB::table($table)->delete());
        }
        $this->dbRefuses(fn () => DB::statement('TRUNCATE tax_party_profile_versions CASCADE'));
        $row = (array) DB::table('tax_party_component_evidence')->first();
        unset($row['id']);
        $row['component_id'] = DB::table('tax_components')->insertGetId(['company_id' => 1, 'code' => 'post-activation']);
        $this->dbRefuses(fn () => DB::table('tax_party_component_evidence')->insert($row));
        $migration = require database_path('migrations/2026_10_22_140000_create_tax_party_profiles.php');
        $this->assertThrows(fn () => $migration->down(), \RuntimeException::class);
        $this->assertDatabaseCount('tax_party_profile_versions', 1);
    }

    public function test_raw_invalid_successions_overlaps_and_component_evidence_refuse(): void
    {
        $this->enable();
        $profile = $this->create();
        $row = (array) DB::table('tax_party_profile_versions')->first();
        unset($row['id']);
        foreach ([['effective_to' => '2025-01-01'], ['version_no' => 2], ['jurisdiction_evidence' => ' '], ['version_no' => 2, 'predecessor_id' => $profile, 'effective_from' => '2026-12-01', 'effective_to' => '2028-01-01'], []] as $changes) {
            $this->dbRefuses(fn () => DB::table('tax_party_profile_versions')->insert(array_replace($row, $changes)));
        }
        $child = (array) DB::table('tax_party_component_evidence')->first();
        unset($child['id']);
        $child['component_id'] = DB::table('tax_components')->insertGetId(['company_id' => 1, 'code' => 'invalid-child']);
        foreach ([['disposition' => 'FREE'], ['grounds' => ' '], ['issuer' => null], ['issued_on' => '2026-02-01'], ['expires_on' => '2026-12-01']] as $changes) {
            $this->dbRefuses(fn () => DB::table('tax_party_component_evidence')->insert(array_replace($child, $changes)));
        }
        $this->assertThrows(fn () => $this->create($profile, '2027-01-01', null, 'uncovered'), TaxRefusal::class);
    }

    public function test_foreign_company_ids_are_scoped_and_every_raw_relationship_includes_company(): void
    {
        $this->enable();
        $profile = $this->create();
        $company = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $party = DB::table('parties')->insertGetId(['company_id' => $company, 'code' => 'other', 'name' => 'Other']);
        $component = DB::table('tax_components')->insertGetId(['company_id' => $company, 'code' => 'other']);
        $foreignActor = DB::table('users')->insertGetId(['company_id' => $company, 'name' => 'Other', 'email' => 'fr2b@example.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $row = (array) DB::table('tax_party_profile_versions')->first();
        unset($row['id']);
        foreach ([['party_id' => $party], ['created_by' => $foreignActor]] as $changes) {
            $this->dbRefuses(fn () => DB::table('tax_party_profile_versions')->insert(array_replace($row, $changes, ['jurisdiction' => 'other'])));
        }
        $foreign = DB::table('tax_party_profile_versions')->insertGetId(array_replace($row, ['company_id' => $company, 'party_id' => $party, 'created_by' => $foreignActor]));
        $this->assertNull(TaxPartyProfileVersion::query()->find($foreign));
        $this->assertThrows(fn () => $this->activate($foreign), TaxRefusal::class);
        $this->assertThrows(fn () => $this->create(party: $party), TaxRefusal::class);
        $child = (array) DB::table('tax_party_component_evidence')->first();
        unset($child['id']);
        $child['component_id'] = DB::table('tax_components')->insertGetId(['company_id' => 1, 'code' => 'tenant-fk-probe']);
        foreach ([['component_id' => $component], ['profile_version_id' => $foreign], ['created_by' => $foreignActor]] as $changes) {
            $this->dbRefuses(fn () => DB::table('tax_party_component_evidence')->insert(array_replace($child, $changes)));
        }
        $this->dbRefuses(fn () => DB::table('tax_party_profile_activations')->insert(['company_id' => 1, 'profile_version_id' => $profile, 'activated_by' => $foreignActor, 'reason' => 'test']));
    }

    public function test_sensitive_evidence_never_enters_party_nested_serialization_activity_or_error_payloads(): void
    {
        $this->enable();
        $profile = $this->create();
        $this->activate($profile);
        foreach ([TaxPartyProfileVersion::class, TaxPartyComponentEvidence::class, TaxPartyProfileActivation::class] as $model) {
            $record = $model::query()->firstOrFail();
            $this->assertSame([], $record->toArray());
            $this->assertSame('[]', $record->toJson());
            $this->assertStringNotContainsString('protected-', print_r($record, true));
            $this->assertSame(0, DB::table('activity_log')->where('subject_type', $model)->count());
        }
        $ordinary = User::factory()->create();
        $this->actingAs($ordinary);
        $baseline = (new PartyResource($this->party))->resolve();
        $this->party->setRelation('taxProfile', TaxPartyProfileVersion::query()->findOrFail($profile));
        $this->assertSame($baseline, (new PartyResource($this->party))->resolve());
        $this->assertSame([], $this->party->toArray()['tax_profile']);
        foreach (['/api/v1/parties', '/api/v1/parties/'.$this->party->id, '/api/v1/parties?include=customer_account,supplier_account', '/api/v1/parties?filter[search]=protected-registration-723', '/api/v1/parties?filter[search]=protected-grounds-723'] as $url) {
            $response = $this->getJson($url);
            $response->assertOk();
            $this->assertStringNotContainsString('protected-', $response->getContent());
            $this->assertStringNotContainsString('text-reference-723', $response->getContent());
            $this->assertStringNotContainsString('tax_profile', $response->getContent());
        }
        $this->getJson('/api/v1/parties?include=taxProfile')->assertUnprocessable();
        $this->getJson('/api/v1/parties?filter[tax_profile]=EXEMPT')->assertUnprocessable();
        $this->getJson('/api/v1/parties?filter[search]=protected-registration-723')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/parties?filter[search]=protected-grounds-723')->assertJsonPath('meta.total', 0);
        try {
            $this->create($profile, '2027-01-01', null, 'private-invalid');
            $this->fail('Uncovered evidence was accepted.');
        } catch (TaxRefusal $e) {
            $this->assertSame('Invalid Tax profile definition.', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('protected-', json_encode($e, JSON_THROW_ON_ERROR));
        }
        $this->assertSame(0, DB::table('activity_log')->whereRaw('properties::text LIKE ?', ['%protected-%'])->count());
    }

    public function test_independent_connection_cannot_activate_or_append_evidence_past_held_generation_fence(): void
    {
        $this->enable();
        $profile = $this->create();
        $config = config('database.connections.testing');
        $contender = new \PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
        $contender->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $contender->exec("SET lock_timeout = '100ms'");
        foreach ([
            "INSERT INTO tax_party_profile_activations(company_id, profile_version_id, activated_by, reason) VALUES (1, {$profile}, {$this->actor->id}, 'test')",
            "INSERT INTO tax_party_component_evidence(company_id, profile_version_id, component_id, disposition, created_by, reason) VALUES (1, {$profile}, {$this->component}, 'UNKNOWN', {$this->actor->id}, 'test')",
        ] as $statement) {
            try {
                $contender->exec($statement);
                $this->fail('Contender passed the held generation fence.');
            } catch (\PDOException $e) {
                $this->assertSame('55P03', $e->getCode());
            }
        }
    }
}
