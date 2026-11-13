<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Queries;

use App\Models\User;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\JobAccess;
use App\Modules\Jobs\Support\JobsException;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Support\CompanyContext;
use App\Support\Scope\CompanyAccessFence;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Disabled internal read foundation (#124). Every call requires exact ACTIVE job.view / ALL.
 * Materialise under the existing company access fence: no cached actor, builder or model escapes.
 * No source or Job locks: each detail SQL statement reads a coherent PostgreSQL snapshot; history
 * is bounded by its captured head generation. No command, permission registration or public API.
 *
 * list: SQL count/page, 25 by default (1–100); number/short description literal substring,
 * state, latest responsible member, open-hold flag and inclusive UTC creation-date range.
 * Sort by number/state/creation/release/start, NULLs last, id as the deterministic tie-breaker.
 * detail: operational summary, latest immutable baseline/accepted component, assignment window,
 * open holds and default activity. Only independently eligible quotation readers get a Sales
 * family/revision reference. Unsupported or absent origins disclose no origin reference.
 * history: SQL count/page, 50 by default (1–100), server sequence ascending; claimed occurrence
 * time is labelled separately. Only RELEASE/ASSIGN/START/HOLD/RESOLVE_HOLD are supported.
 * Missing and foreign IDs share JOB_NOT_FOUND. Close-only and limited actors are refused.
 */
final class ReadJobs
{
    /** @return LengthAwarePaginator<int, JobReadProjection> */
    public function list(?User $actor, ?JobReadFilter $filter = null): LengthAwarePaginator
    {
        return $this->authorised($actor, function () use ($filter): LengthAwarePaginator {
            $filter ??= new JobReadFilter;
            $query = DB::query()->fromSub($this->summary(), 'read_jobs')->select('read_jobs.*');
            if ($filter->state !== null) {
                $query->where('state', $filter->state);
            }
            if ($filter->responsibleMemberId !== null) {
                $query->where('responsible_id', $filter->responsibleMemberId);
            }
            if ($filter->hasOpenHold !== null) {
                $query->where('has_open_hold', $filter->hasOpenHold);
            }
            if ($filter->createdFrom !== null) {
                $query->where('created_at', '>=', $filter->createdFrom.'T00:00:00Z');
            }
            if ($filter->createdUntil !== null) {
                $query->whereRaw('created_at < (?::date + 1)::timestamp AT TIME ZONE \'UTC\'', [$filter->createdUntil]);
            }
            if ($filter->search !== null && trim($filter->search) !== '') {
                // Literal substring, not a caller-controlled SQL wildcard or a protected-field search.
                $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filter->search)).'%';
                $query->where(fn (Builder $q) => $q->where('number', 'ilike', $needle)->orWhere('description', 'ilike', $needle));
            }
            $query->orderByRaw('"'.$filter->sort.'" '.$filter->direction.' NULLS LAST')->orderBy('id', $filter->direction);

            return $query->paginate($filter->perPage, ['*'], 'page', $filter->page)->through(JobReadProjection::summary(...));
        });
    }

    public function detail(?User $actor, int $jobId): JobReadProjection
    {
        return $this->authorised($actor, function () use ($actor, $jobId): JobReadProjection {
            $query = $this->summary()->where('j.id', $jobId);
            $query->selectSub($this->baseline()->leftJoin('job_baseline_components as bc', function ($join): void {
                $join->on('bc.company_id', 'b.company_id')->on('bc.job_id', 'b.job_id')->on('bc.baseline_id', 'b.id');
            })->selectRaw("jsonb_build_object('version', b.version, 'scope_description', b.scope_description,
                'deliverable', bc.deliverable_text, 'agreed_conditions', bc.agreed_conditions, 'recorded_at', b.recorded_at)"), 'baseline');
            $query->selectSub($this->assignment()->leftJoin('users as member', function ($join): void {
                $join->on('member.company_id', 'a.company_id')->on('member.id', 'a.responsible_user_id');
            })->selectRaw("jsonb_build_object('member_id', a.responsible_user_id, 'member_name', member.name,
                'intended_from', a.intended_from, 'intended_until', a.intended_until, 'recorded_at', a.recorded_at)"), 'assignment');
            $query->selectSub($this->openHolds()->selectRaw("COALESCE(jsonb_agg(jsonb_build_object('id', h.id, 'reason', h.reason,
                'effective_from', h.effective_from, 'recorded_at', h.recorded_at) ORDER BY he.sequence), '[]'::jsonb)"), 'open_holds');
            $query->selectSub(DB::table('job_activities as activity')->whereColumn('activity.company_id', 'j.company_id')->whereColumn('activity.job_id', 'j.id')
                ->where('activity.kind', 'DEFAULT')->selectRaw("jsonb_build_object('id', activity.id, 'description', activity.description)"), 'activity');
            $row = $query->first() ?? $this->notFound();

            return JobReadProjection::detail($row, $this->salesOrigin($actor, $jobId));
        });
    }

    /** @return LengthAwarePaginator<int, JobReadProjection> */
    public function history(?User $actor, int $jobId, int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        return $this->authorised($actor, function () use ($jobId, $page, $perPage): LengthAwarePaginator {
            JobReadFilter::pagination($page, $perPage);
            $company = app(CompanyContext::class)->id();
            $head = ServiceJob::query()->where('company_id', $company)->whereKey($jobId)->first(['generation']) ?? $this->notFound();
            $query = DB::table('job_events as e')->where('e.company_id', $company)->where('e.job_id', $jobId)
                ->where('e.next_generation', '<=', $head->generation)->whereIn('e.operation', ['RELEASE', 'ASSIGN', 'START', 'HOLD', 'RESOLVE_HOLD']);
            foreach (['job_assignment_events as a', 'job_holds as h', 'job_hold_resolutions as r'] as $table) {
                $alias = substr($table, -1);
                $query->leftJoin($table, function ($join) use ($alias): void {
                    $join->on($alias.'.company_id', 'e.company_id')->on($alias.'.job_id', 'e.job_id')->on($alias.'.event_id', 'e.id');
                });
            }
            $query->leftJoin('users as actor', fn ($join) => $join->on('actor.company_id', 'e.company_id')->on('actor.id', 'e.actor_id'));
            $query->select(['e.id', 'e.sequence', 'e.operation', 'e.from_state', 'e.to_state', 'e.recorded_at', 'e.occurred_at', 'e.occurrence_precision',
                'e.actor_id', 'actor.name as actor_name', 'e.reason', 'a.responsible_user_id'])->selectRaw('COALESCE(h.id, r.hold_id) as hold_id');

            // Server sequence is authoritative even when timestamps tie or a claimed time goes backwards.
            return $query->orderBy('e.sequence')->orderBy('e.id')->paginate($perPage, ['*'], 'page', $page)->through(JobReadProjection::event(...));
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    private function authorised(?User $actor, Closure $read): mixed
    {
        JobAccess::require($actor, 'job.view', 'read Jobs');

        return DB::transaction(function () use ($actor, $read): mixed {
            app(CompanyAccessFence::class)->lock();
            JobAccess::require($actor, 'job.view', 'read Jobs');

            return $read();
        });
    }

    private function summary(): Builder
    {
        $jobs = ServiceJob::query()->where('company_id', app(CompanyContext::class)->id())
            ->select(['id', 'company_id', 'code', 'state', 'generation', 'created_at'])->toBase();

        return DB::query()->fromSub($jobs, 'j')
            ->select(['j.id', 'j.code as number', 'j.state', 'j.created_at'])
            ->selectSub($this->baseline()->selectRaw('left(b.scope_description, 160)'), 'description')
            ->selectSub($this->assignment()->select('a.responsible_user_id'), 'responsible_id')
            ->selectSub($this->assignment()->join('users as member', fn ($join) => $join->on('member.company_id', 'a.company_id')->on('member.id', 'a.responsible_user_id'))->select('member.name'), 'responsible_name')
            ->selectRaw('EXISTS ('.$this->openHolds()->selectRaw('1')->toSql().') as has_open_hold')
            ->selectSub($this->eventTime('RELEASE'), 'released_at')->selectSub($this->eventTime('START'), 'started_at');
    }

    private function baseline(): Builder
    {
        return DB::table('job_baselines as b')->whereColumn('b.company_id', 'j.company_id')->whereColumn('b.job_id', 'j.id')->orderByDesc('b.version')->limit(1);
    }

    private function assignment(): Builder
    {
        return DB::table('job_assignment_events as a')->join('job_events as ae', fn ($join) => $join->on('ae.company_id', 'a.company_id')->on('ae.job_id', 'a.job_id')->on('ae.id', 'a.event_id'))
            ->whereColumn('a.company_id', 'j.company_id')->whereColumn('a.job_id', 'j.id')->whereColumn('ae.next_generation', '<=', 'j.generation')->orderByDesc('a.sequence')->limit(1);
    }

    private function openHolds(): Builder
    {
        return DB::table('job_holds as h')->join('job_events as he', fn ($join) => $join->on('he.company_id', 'h.company_id')->on('he.job_id', 'h.job_id')->on('he.id', 'h.event_id'))
            ->whereColumn('h.company_id', 'j.company_id')->whereColumn('h.job_id', 'j.id')->whereColumn('he.next_generation', '<=', 'j.generation')
            ->whereNotExists(function (Builder $query): void {
                $query->from('job_hold_resolutions as r')->join('job_events as re', fn ($join) => $join->on('re.company_id', 'r.company_id')->on('re.job_id', 'r.job_id')->on('re.id', 'r.event_id'))
                    ->whereColumn('r.company_id', 'h.company_id')->whereColumn('r.job_id', 'h.job_id')->whereColumn('r.hold_id', 'h.id')->whereColumn('re.next_generation', '<=', 'j.generation')->selectRaw('1');
            });
    }

    private function eventTime(string $operation): Builder
    {
        return DB::table('job_events as e')->whereColumn('e.company_id', 'j.company_id')->whereColumn('e.job_id', 'j.id')->whereColumn('e.next_generation', '<=', 'j.generation')
            ->where('e.operation', $operation)->orderBy('e.sequence')->limit(1)->select('e.recorded_at');
    }

    /**
     * A quotation reference only; never the release, acceptance, consent evidence or commercial record.
     *
     * @return array{family_id:int,revision_id:int}|null
     */
    private function salesOrigin(?User $actor, int $jobId): ?array
    {
        if ($actor === null) {
            return null;
        }
        try {
            $families = app(QuotationAccess::class)->queryFor($actor, [PermissionVocabulary::QUOTATION_VIEW])->select('quotation_families.id');
        } catch (AuthorizationException) {
            return null;
        }
        $row = DB::table('job_sales_origins as o')->join('handover_release_events as re', fn ($join) => $join->on('re.company_id', 'o.company_id')->on('re.id', 'o.release_event_id'))
            ->join('handover_baselines as hb', fn ($join) => $join->on('hb.company_id', 're.company_id')->on('hb.id', 're.baseline_id'))
            ->join('quotation_issued_lines as il', fn ($join) => $join->on('il.company_id', 'hb.company_id')->on('il.id', 'hb.issued_line_id'))
            ->where('o.company_id', app(CompanyContext::class)->id())->where('o.job_id', $jobId)->whereIn('il.family_id', $families)->first(['il.family_id', 'il.revision_id']);

        return $row === null ? null : ['family_id' => (int) $row->family_id, 'revision_id' => (int) $row->revision_id];
    }

    private function notFound(): never
    {
        throw new JobsException('The Job was not found.', 'job_id', 'JOB_NOT_FOUND');
    }
}
