<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\Category\IndexCategoryRequest;
use App\Modules\Inventory\Http\Requests\Category\StoreCategoryRequest;
use App\Modules\Inventory\Http\Requests\Category\UpdateCategoryRequest;
use App\Modules\Inventory\Http\Resources\CategoryResource;
use App\Modules\Inventory\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A hierarchy cycle or a parent_id/account id from another company is rejected
 * by Category's own guards (GuardsCompanyScopedHierarchy, GuardsRelatedCompanyScope)
 * and surfaces as a 422 via App\Exceptions\DomainInvariantException — see
 * bootstrap/app.php. The request-layer CompanyScoped::exists() checks below catch
 * the common case earlier with a field-specific message; the model guard is what
 * makes the cycle case (which no exists() check alone can express) safe too.
 */
class CategoryController extends Controller
{
    /** @var array<string, string> */
    private const INCLUDABLE = [
        'parent' => 'parent',
        'children' => 'children',
    ];

    public function index(IndexCategoryRequest $request): AnonymousResourceCollection
    {
        $query = Category::query();

        $this->applyIncludes($query, $request);

        if ($request->filled('filter.parent_id')) {
            $parentId = (int) $request->input('filter.parent_id');
            $parentId === 0 ? $query->whereNull('parent_id') : $query->where('parent_id', $parentId);
        }

        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%"));
        }

        $query->orderBy('name');

        $perPage = $request->integer('per_page', 25);

        return CategoryResource::collection($query->paginate($perPage));
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function show(Request $request, Category $category): CategoryResource
    {
        return new CategoryResource($category->load($this->requestedIncludes($request)));
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $category->update($request->validated());

        return new CategoryResource($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  Builder<Category>  $query
     */
    private function applyIncludes(Builder $query, Request $request): void
    {
        foreach ($this->requestedIncludes($request) as $relation) {
            $query->with($relation);
        }
    }

    /**
     * @return list<string>
     */
    private function requestedIncludes(Request $request): array
    {
        $requested = array_filter(explode(',', (string) $request->query('include', '')));

        return array_values(array_intersect_key(self::INCLUDABLE, array_flip($requested)));
    }
}
