<?php

namespace Tests\E2E\RealBackend;

use App\Models\User;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyContact;
use App\Modules\Parties\Models\PartySite;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\PreviewServiceRelease;
use App\Modules\Sales\Actions\ReleaseHandoverComponent;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Support\JobSource\JobSourceFamilies;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Sales\Concerns\BuildsHandoverFixture;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Support\Sales\ScopedReleaseFixtures;
use Tests\TestCase;

/**
 * Builds the COMMITTED world the real-backend browser harness runs against (docs/architecture/real-backend-browser-tests.md) and writes it as JSON for the browser specs. It is not
 * part of any test suite (tests/E2E is in no phpunit.xml suite) and does nothing unless the harness set ERP_E2E_WORLD_FILE and the database is the private `*_e2e` one. Everything
 * it creates is real data made through the real Actions (issuing, acceptance, preparation, basis, release); the only things that are not production behaviour are the process-local
 * authority and the source-family stand-ins in RealBackendAuthority, applied here exactly as the server applies them.
 *
 * Tokens are Sanctum personal access tokens of throwaway users in a throwaway database. The world is the reusable fixture for later journeys (Jobs execution): see `world.jobs`.
 */
class BuildWorldTest extends TestCase
{
    use BuildsHandoverFixture;
    use BuildsIssuableQuotations;
    use ScopedReleaseFixtures;

    protected function handoverDuties(): array
    {
        return RealBackendAuthority::SALES;
    }

    private function holder(string $label, array $duties, int $company = 1): array
    {
        $id = (int) DB::table('users')->insertGetId(['company_id' => $company, 'name' => $label, 'email' => strtolower(str_replace(' ', '.', $label)).'@e2e.invalid', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company);
        $user = User::withoutGlobalScopes()->findOrFail($id);
        foreach ($duties as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $user->givePermissionTo($duties);
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);

        return ['id' => $id, 'name' => $label, 'company' => $company, 'duties' => array_values($duties), 'token' => $user->createToken('e2e')->plainTextToken];
    }

    /** @return array<string,mixed> */
    private function scenario(string $tag, bool $onSite): array
    {
        $f = $this->serviceAcceptance($this->checker, $this->customer->id, 'SERVICE_ITEM', $tag);
        PartyContact::query()->whereKey($f['contact'])->update(['name' => "Amina Khan $tag", 'email' => "amina.$tag@example.invalid"]);
        $second = PartyContact::create(['party_id' => $this->customer->id, 'name' => "Bilal Raza $tag", 'phone' => '+92 300 000'.random_int(1000, 9999)]);
        $site = PartySite::create(['party_id' => $this->customer->id, 'code' => 'KHI'.$tag, 'name' => "Karachi plant $tag", 'address_line1' => '12 Port Road', 'city' => 'Karachi', 'country_code' => 'PK', 'status' => 'ACTIVE']);

        return ['tag' => $tag, 'on_site' => $onSite, 'acceptance' => $f['acceptance'], 'case' => $f['case'], 'contact' => $f['contact'], 'contact2' => $second->id, 'site' => $site->id, 'inputs' => $f['inputs']];
    }

    private function prepare(array $s, ?int $site = null): array
    {
        $inputs = $s['inputs'] + ($site ? ['site_id' => $site, 'location_kind' => 'ON_SITE'] : []);
        $token = app(PreviewHandover::class)($this->checker, $s['acceptance'], $inputs)['preview_token'];
        $out = app(PrepareHandoverPlan::class)($this->checker, 'prepare-'.$s['tag'], $s['acceptance'], $inputs, $token);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        return ['plan' => $out['plan_id'], 'stage' => (int) DB::table('handover_stages')->orderByDesc('id')->value('id')];
    }

    private function agreement(array $s, string $variant, string $materials = 'EXCLUDED'): int
    {
        return $this->appendAgreementEvidence($s['case'], $this->checker->id, $this->scopeDeclaration($s['acceptance'], $materials, 'EXCLUDED'));
    }

    public function test_build_the_real_backend_world(): void
    {
        $file = getenv('ERP_E2E_WORLD_FILE');
        if ($file === false || $file === '' || ! preg_match('/^erp_testing_[a-z0-9_]*e2e$/', (string) getenv('TEST_DB_DATABASE'))) {
            $this->markTestSkipped('Only the real-backend harness builds this world (ERP_E2E_WORLD_FILE and a private *_e2e database).');
        }
        $this->setUpIssuing();
        $this->activateHandover();
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest(array_map(
            fn (string $n) => new JobDuty($n, JobDutyStatus::Active, 'op', 'projection', 'gate'), RealBackendAuthority::JOBS)));
        $this->app->instance(JobSourceFamilies::class, NoBindingFamilies::registry());
        foreach ([...RealBackendAuthority::SALES, ...RealBackendAuthority::JOBS] as $duty) {
            Permission::findOrCreate($duty, 'web');
            $this->checker->givePermissionTo($duty);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Another customer's contact and site: must never be offered for these acceptances.
        $other = Party::create(['code' => 'OTHER-CUST', 'name' => 'Another Customer Ltd']);
        PartyContact::create(['party_id' => $other->id, 'name' => 'Foreign Contact', 'email' => 'foreign@example.invalid']);
        PartySite::create(['party_id' => $other->id, 'code' => 'OTH', 'name' => 'Foreign Site', 'status' => 'ACTIVE']);

        // Fresh: accepted, nothing prepared. A written, authority-verified agreement message already sits on the case (customer correspondence), so recording genuine conditions is a choice.
        $remote = $this->scenario('r1', false);
        $remote['agreement_evidence'] = $this->agreement($remote, 'msg');
        $onsite = $this->scenario('s1', true);
        $onsite['agreement_evidence'] = $this->agreement($onsite, 'msg');

        // Evidence: prepared; a written agreement message that was CORRECTED, plus its current correction.
        $evidence = $this->scenario('e1', false);
        $evidence += $this->prepare($evidence);
        $evidence['superseded_evidence'] = $this->agreement($evidence, 'old');
        $evidence['current_evidence'] = (int) CustomerDecisionEvidence::create(['case_id' => $evidence['case'], 'predecessor_id' => $evidence['superseded_evidence'], 'source_kind' => 'EMAIL',
            'authority_verification_basis' => 'DOCUMENT_ON_FILE', 'customer_representative_snapshot' => 'Synthetic buyer', 'occurrence_precision' => 'UNKNOWN',
            'factual_description' => $this->scopeDeclaration($evidence['acceptance']), 'observation_state' => 'OBSERVED', 'recorded_by' => $this->checker->id])->id;

        // Prepared, but nothing written from the customer and no conditions recorded.
        $bare = $this->scenario('b1', false);
        $bare += $this->prepare($bare);

        // Prepared with genuine conditions recorded: ready to release. Separate ones so no test depends on another's outcome.
        $ready = function (string $tag): array {
            $s = $this->scenario($tag, false);
            $s += $this->prepare($s);
            $this->recordScopedBasis($this->checker, 'basis-'.$tag, $s['acceptance'], $s['case']);

            return $s;
        };
        $stale = $ready('t1');
        $race = $ready('d1');
        $dup = $ready('p1');
        $norecv = $ready('n1');

        // Already released through the real Actions: one Job.
        $released = $ready('x1');
        $preview = app(PreviewServiceRelease::class)($this->checker, $released['stage']);
        $out = app(ReleaseHandoverComponent::class)($this->checker, 'release-x1', $released['stage'], (int) $preview['basis_id'], $preview['fingerprint']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $released += ['job' => $out['job_id'], 'job_code' => $out['job_code']];

        $officer = [P::QUOTATION_VIEW, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_PREPARE, P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE, 'job.receive', 'job.view'];
        $company2 = (int) DB::table('companies')->insertGetId(['name' => 'Foreign Company', 'created_at' => now(), 'updated_at' => now()]);

        $revoked = $this->holder('E2E Revoked Officer', $officer);
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        User::withoutGlobalScopes()->findOrFail($revoked['id'])->revokePermissionTo([P::HANDOVER_RELEASE, P::HANDOVER_COMPLETION_BASIS_RECORD]);
        $revoked['duties'] = array_values(array_diff($revoked['duties'], [P::HANDOVER_RELEASE, P::HANDOVER_COMPLETION_BASIS_RECORD]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $world = [
            'users' => [
                // A real token whose release grants were REVOKED after it was issued: what the server decides, whatever a stale screen still shows.
                'revoked_officer' => $revoked + ['stale_screen_duties' => $officer],
                'officer' => $this->holder('E2E Officer', $officer),
                'preparer' => $this->holder('E2E Preparer', [P::QUOTATION_VIEW, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_PREPARE]),
                'viewer' => $this->holder('E2E Viewer', [P::QUOTATION_VIEW, P::CUSTOMER_DECISION_VIEW]),
                'releaser_without_jobs' => $this->holder('E2E Releaser No Jobs', [P::QUOTATION_VIEW, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE]),
                'officer_without_job_view' => $this->holder('E2E Officer No Job View', [P::QUOTATION_VIEW, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_PREPARE, P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE, 'job.receive']),
                'evidence_recorder' => $this->holder('E2E Evidence Recorder', [P::QUOTATION_VIEW, P::CUSTOMER_DECISION_VIEW, P::CUSTOMER_DECISION_EVIDENCE_RECORD]),
                'foreign_officer' => $this->holder('E2E Foreign Officer', $officer, $company2),
            ],
            'scenarios' => compact('remote', 'onsite', 'evidence', 'bare', 'stale', 'race', 'dup', 'norecv', 'released'),
            'decoys' => ['foreign_contact' => (int) DB::table('party_contacts')->where('name', 'Foreign Contact')->value('id'), 'foreign_site' => (int) DB::table('party_sites')->where('name', 'Foreign Site')->value('id')],
            // The reusable Jobs fixture: the released Job above (state RELEASED, no responsibility yet) and the Jobs-capable holders. Agent 2's execution journey starts here.
            'jobs' => ['released_job' => $released['job'], 'released_job_code' => $released['job_code'], 'job_holders' => ['officer', 'officer_without_job_view']],
            'duties' => ['sales' => RealBackendAuthority::SALES, 'jobs' => RealBackendAuthority::JOBS],
        ];
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        file_put_contents($file, json_encode($world, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->assertSame(1, ServiceJob::query()->count());
    }
}
