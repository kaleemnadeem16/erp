<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\ArchiveAssemblyGroup;
use App\Modules\Estimating\Actions\CreateAssemblyGroup;
use App\Modules\Estimating\Actions\UpdateAssemblyGroup;
use App\Modules\Estimating\Http\Requests\AssemblyGroup\IndexAssemblyGroupRequest;
use App\Modules\Estimating\Http\Requests\AssemblyGroup\ShowAssemblyGroupRequest;
use App\Modules\Estimating\Http\Requests\AssemblyGroup\StoreAssemblyGroupRequest;
use App\Modules\Estimating\Http\Requests\AssemblyGroup\UpdateAssemblyGroupRequest;
use App\Modules\Estimating\Http\Resources\AssemblyGroupResource;
use App\Modules\Estimating\Models\AssemblyGroup;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AssemblyGroupController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['assemblies' => 'assemblies'];

    private const SORTABLE = ['code' => 'code', 'created_at' => 'created_at'];

    public function index(IndexAssemblyGroupRequest $request): AnonymousResourceCollection
    {
        $query = AssemblyGroup::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"));
        }
        $this->applySort($query, $request, self::SORTABLE, 'code');

        return AssemblyGroupResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreAssemblyGroupRequest $request, CreateAssemblyGroup $create): JsonResponse
    {
        return (new AssemblyGroupResource($create($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function show(ShowAssemblyGroupRequest $request, AssemblyGroup $assembly_group): AssemblyGroupResource
    {
        return new AssemblyGroupResource($assembly_group->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateAssemblyGroupRequest $request, AssemblyGroup $assembly_group, UpdateAssemblyGroup $update): AssemblyGroupResource
    {
        return new AssemblyGroupResource($update($assembly_group, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, AssemblyGroup $assembly_group, ArchiveAssemblyGroup $archive): Response
    {
        abort_unless($request->user()?->can('estimating.assembly-group.manage'), 403);
        $archive($assembly_group, $request->user());

        return response()->noContent();
    }
}
