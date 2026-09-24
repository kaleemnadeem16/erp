<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\ArchiveAssembly;
use App\Modules\Estimating\Actions\CreateAssembly;
use App\Modules\Estimating\Actions\UpdateAssembly;
use App\Modules\Estimating\Http\Requests\Assembly\IndexAssemblyRequest;
use App\Modules\Estimating\Http\Requests\Assembly\ShowAssemblyRequest;
use App\Modules\Estimating\Http\Requests\Assembly\StoreAssemblyRequest;
use App\Modules\Estimating\Http\Requests\Assembly\UpdateAssemblyRequest;
use App\Modules\Estimating\Http\Resources\AssemblyResource;
use App\Modules\Estimating\Models\Assembly;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AssemblyController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = [
        'workUom' => 'workUom', 'assemblyGroup' => 'assemblyGroup',
        'versions' => 'versions', 'versions.components' => 'versions.components',
        'activeVersion' => 'activeVersion', 'activeVersion.components' => 'activeVersion.components',
    ];

    private const SORTABLE = ['code' => 'code', 'created_at' => 'created_at'];

    public function index(IndexAssemblyRequest $request): AnonymousResourceCollection
    {
        $query = Assembly::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"));
        }
        foreach (['assembly_group_id', 'status'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }
        $this->applySort($query, $request, self::SORTABLE, 'code');

        return AssemblyResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreAssemblyRequest $request, CreateAssembly $create): JsonResponse
    {
        return (new AssemblyResource($create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(ShowAssemblyRequest $request, Assembly $assembly): AssemblyResource
    {
        return new AssemblyResource($assembly->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateAssemblyRequest $request, Assembly $assembly, UpdateAssembly $update): AssemblyResource
    {
        return new AssemblyResource($update($assembly, $request->validated()));
    }

    public function destroy(Assembly $assembly, ArchiveAssembly $archive): Response
    {
        $archive($assembly);

        return response()->noContent();
    }
}
