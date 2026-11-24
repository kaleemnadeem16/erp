<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Modules\Jobs\Http\Requests\EligibleJobAssigneesRequest;
use App\Modules\Jobs\Http\Resources\JobEligibleAssigneeResource;
use App\Modules\Jobs\Queries\ReadEligibleJobAssignees;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class JobAssignmentLookupController extends Controller
{
    /** @return AnonymousResourceCollection<JobEligibleAssigneeResource> */
    #[Response(404, 'The Job was not found.', type: 'array{message:string}')]
    #[Response(200, 'Searchable eligible employees for this Job assignment only.', type: 'array{data:list<JobEligibleAssigneeResource>,links:array{first:string,last:string,prev:string|null,next:string|null},meta:array{current_page:int,from:int|null,last_page:int,links:list<array{url:string|null,label:string,page?:int|null,active:bool}>,path:string,per_page:int,to:int|null,total:int}}')]
    public function jobsEligibleAssignees(EligibleJobAssigneesRequest $request, int $job, ReadEligibleJobAssignees $query): AnonymousResourceCollection
    {
        try {
            return JobEligibleAssigneeResource::collection($query->read($request->user(), $job,
                $request->string('search')->toString(), $request->integer('page', 1), $request->integer('per_page', 20))->withQueryString());
        } catch (DomainInvariantException $exception) {
            if ($exception->reasonCode === 'JOB_NOT_FOUND') {
                throw new NotFoundHttpException('The Job was not found.');
            }
            throw $exception;
        }
    }
}
