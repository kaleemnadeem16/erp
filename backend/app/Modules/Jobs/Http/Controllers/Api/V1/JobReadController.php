<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Jobs\Http\Requests\JobHistoryRequest;
use App\Modules\Jobs\Http\Requests\ListJobsRequest;
use App\Modules\Jobs\Http\Requests\ShowJobRequest;
use App\Modules\Jobs\Http\Resources\JobDetailResource;
use App\Modules\Jobs\Http\Resources\JobHistoryResource;
use App\Modules\Jobs\Http\Resources\JobSummaryResource;
use App\Modules\Jobs\Queries\ReadJobs;
use App\Modules\Jobs\Support\JobsException;
use Closure;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class JobReadController extends Controller
{
    /** @return AnonymousResourceCollection<JobSummaryResource> */
    #[Response(200, 'Paginated safe Jobs summaries.', type: 'array{data:list<JobSummaryResource>,links:array{first:string,last:string,prev:string|null,next:string|null},meta:array{current_page:int,from:int|null,last_page:int,links:list<array{url:string|null,label:string,page?:int|null,active:bool}>,path:string,per_page:int,to:int|null,total:int}}')]
    public function index(ListJobsRequest $request, ReadJobs $reads): AnonymousResourceCollection
    {
        return JobSummaryResource::collection($reads->list($request->user(), $request->readFilter())->withQueryString());
    }

    #[Response(404, 'The Job was not found.', type: 'array{message:string}')]
    #[Response(200, 'Safe operational Job details.', type: 'array{data:JobDetailResource}')]
    public function show(ShowJobRequest $request, int $job, ReadJobs $reads): JobDetailResource
    {
        return new JobDetailResource($this->found(fn () => $reads->detail($request->user(), $job)));
    }

    /** @return AnonymousResourceCollection<JobHistoryResource> */
    #[Response(404, 'The Job was not found.', type: 'array{message:string}')]
    #[Response(200, 'Operational history in server sequence order.', type: 'array{data:list<JobHistoryResource>,links:array{first:string,last:string,prev:string|null,next:string|null},meta:array{current_page:int,from:int|null,last_page:int,links:list<array{url:string|null,label:string,page?:int|null,active:bool}>,path:string,per_page:int,to:int|null,total:int}}')]
    public function history(JobHistoryRequest $request, int $job, ReadJobs $reads): AnonymousResourceCollection
    {
        return $this->found(fn () => JobHistoryResource::collection($reads->history($request->user(), $job,
            $request->integer('page', 1), $request->integer('per_page', 50))->withQueryString()));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    private function found(Closure $read): mixed
    {
        try {
            return $read();
        } catch (JobsException $exception) {
            if ($exception->reasonCode === 'JOB_NOT_FOUND') {
                throw new NotFoundHttpException('The Job was not found.');
            }
            throw $exception;
        }
    }
}
