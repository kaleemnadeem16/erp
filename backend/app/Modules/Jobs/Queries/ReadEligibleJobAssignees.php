<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Queries;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Jobs\Support\JobAccess;
use App\Modules\Jobs\Support\JobsException;
use App\Support\CompanyContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ReadEligibleJobAssignees
{
    public function __construct(private readonly ReadJobOperationContext $contexts) {}

    /** @return LengthAwarePaginator<int, EligibleJobAssigneeProjection> */
    public function read(?User $actor, int $jobId, string $search, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        JobAccess::require($actor, 'job.assign', 'choose a responsible employee');
        $search = trim($search);
        if (mb_strlen($search) < 2 || mb_strlen($search) > 100 || $page < 1 || $page > 100000 || $perPage < 1 || $perPage > 50) {
            throw new JobsException('Search needs 2 to 100 characters and bounded pagination.', 'search', 'INVALID_INPUT');
        }

        return DB::transaction(function () use ($actor, $jobId, $search, $page, $perPage): LengthAwarePaginator {
            // Reuse the reviewed ASSIGN eligibility boundary. Its company/source fences remain held by this enclosing read transaction.
            // No employee query or count is permitted until both current authority and source-record eligibility have been proved.
            $this->contexts->read($actor, $jobId, 'ASSIGN');
            JobAccess::require($actor, 'job.assign', 'choose a responsible employee');
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $pageResult = User::query()->where('company_id', app(CompanyContext::class)->id())->where('status', MemberStatus::Active)
                ->where('name', 'ilike', $needle)->orderBy('name')->orderBy('id')->select(['id', 'name'])
                ->paginate($perPage, ['id', 'name'], 'page', $page)
                ->through(fn (User $user): EligibleJobAssigneeProjection => new EligibleJobAssigneeProjection($user->id, $user->name));
            JobAccess::require($actor, 'job.assign', 'choose a responsible employee');

            return $pageResult;
        });
    }
}
