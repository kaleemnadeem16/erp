<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\ArchiveCategory;
use App\Modules\Inventory\Actions\CreateCategory;
use App\Modules\Inventory\Actions\UpdateCategory;
use App\Modules\Inventory\Http\Requests\Category\IndexCategoryRequest;
use App\Modules\Inventory\Http\Requests\Category\ShowCategoryRequest;
use App\Modules\Inventory\Http\Requests\Category\StoreCategoryRequest;
use App\Modules\Inventory\Http\Requests\Category\UpdateCategoryRequest;
use App\Modules\Inventory\Http\Resources\CategoryResource;
use App\Modules\Inventory\Models\Category;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class CategoryController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = [
        'parent' => 'parent',
        'children' => 'children',
    ];

    /** @var array<string, string> */
    private const SORTABLE = [
        'code' => 'code',
        'name' => 'name',
        'created_at' => 'created_at',
    ];

    public function index(IndexCategoryRequest $request): AnonymousResourceCollection
    {
        $query = Category::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        if ($request->filled('filter.parent_id')) {
            $parentId = (int) $request->input('filter.parent_id');
            $parentId === 0 ? $query->whereNull('parent_id') : $query->where('parent_id', $parentId);
        }
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%"));
        }
        $this->applySort($query, $request, self::SORTABLE, 'name');

        return CategoryResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreCategoryRequest $request, CreateCategory $create): JsonResponse
    {
        $category = $create($request->validated(), $request->user());

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function show(ShowCategoryRequest $request, Category $category): CategoryResource
    {
        return new CategoryResource($category->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategory $update): CategoryResource
    {
        return new CategoryResource($update($category, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, Category $category, ArchiveCategory $archive): Response
    {
        abort_unless($request->user()?->can('inventory.category.manage'), 403);
        $archive($category, $request->user());

        return response()->noContent();
    }
}
