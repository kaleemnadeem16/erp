<?php

namespace Tests\Support\Jobs;

use App\Models\User;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * A complete raw Job (component, default activity, lineage) for a release event written by a FAKE receiver, so the Sales source verifier can be exercised against a real,
 * company-scoped Job without the Jobs receiver. One Job per event, whatever the number of calls. Jobs duties are activated through a fixture manifest only.
 */
trait BuildsRawSalesJob
{
    /** @var array<int, int> */
    private array $rawSalesJobs = [];

    /** @param list<string> $duties the Jobs duties the actor is made to hold (and that are made ACTIVE in the fixture manifest) */
    protected function rawSalesJobFor(int $eventId, User $actor, array $duties = ['job.start']): ServiceJob
    {
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest(array_map(fn (string $n): JobDuty => new JobDuty($n, JobDutyStatus::Active, 'op', 'projection', 'gate'), $duties)));
        foreach ($duties as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $actor->givePermissionTo($duties);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        if (isset($this->rawSalesJobs[$eventId])) {
            return ServiceJob::query()->findOrFail($this->rawSalesJobs[$eventId]);
        }
        $event = DB::table('handover_release_events')->find($eventId);
        $company = (int) $event->company_id;
        $now = now();
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $job = (int) DB::table('service_jobs')->insertGetId(['company_id' => $company, 'code' => 'JOB-RAW-'.$eventId, 'state' => 'RELEASED', 'generation' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('job_events')->insert(['company_id' => $company, 'job_id' => $job, 'sequence' => 1, 'operation' => 'RELEASE', 'from_state' => null, 'to_state' => 'RELEASED', 'prior_generation' => 0,
            'next_generation' => 1, 'occurrence_precision' => 'EXACT', 'recorded_at' => $now, 'actor_id' => $actor->id, 'evidence_version' => 1]);
        $baseline = (int) DB::table('job_baselines')->insertGetId(['company_id' => $company, 'job_id' => $job, 'version' => 1, 'fingerprint' => 'fp', 'scope_description' => 'x', 'completion_rule_version' => 'c', 'recorded_at' => $now]);
        DB::table('job_handover_origins')->insert(['company_id' => $company, 'job_id' => $job, 'source_contract_version' => 'sales-handover-release-v1', 'target_purpose' => $event->purpose,
            'business_identity' => 'stage:'.$event->stage_id, 'intent_hash' => $event->intent_hash, 'recorded_at' => $now]);
        DB::table('job_sales_origins')->insert(['company_id' => $company, 'job_id' => $job, 'release_event_id' => $event->id]);
        $hb = DB::table('handover_baselines')->where('id', $event->baseline_id)->first();
        $basis = DB::table('service_completion_bases')->where('id', $event->basis_id)->first();
        DB::table('job_baseline_components')->insert(['company_id' => $company, 'job_id' => $job, 'baseline_id' => $baseline, 'release_event_id' => $event->id,
            'allocation_id' => DB::table('handover_stages')->where('id', $event->stage_id)->value('allocation_id'), 'issued_line_id' => $hb->issued_line_id, 'basis_id' => $basis->id,
            'deliverable_identity' => json_decode($hb->operational_snapshot, true)['deliverable_identity'], 'deliverable_text' => DB::table('quotation_issued_lines')->where('id', $hb->issued_line_id)->value('description'),
            'agreed_conditions' => $basis->agreed_text, 'condition_identity' => $event->condition_identity]);
        DB::table('job_activities')->insert(['company_id' => $company, 'job_id' => $job, 'kind' => 'DEFAULT', 'description' => 'x']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        return ServiceJob::query()->findOrFail($this->rawSalesJobs[$eventId] = $job);
    }
}
