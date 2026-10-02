<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\SetMaterialSelectionPolicy;
use App\Modules\Estimating\Http\Requests\MaterialSelectionPolicy\IndexMaterialSelectionPolicyRequest;
use App\Modules\Estimating\Http\Requests\MaterialSelectionPolicy\StoreMaterialSelectionPolicyRequest;
use App\Modules\Estimating\Http\Resources\MaterialSelectionPolicyResource;
use App\Modules\Estimating\Models\MaterialSelectionPolicy;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The stored material selection strategies (ADR 0071): list the ones in force
 * and their history, and record a new choice. There is no update or delete —
 * a change is a new row that supersedes the old one.
 */
class MaterialSelectionPolicyController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['changedBy' => 'changedBy'];

    private const SORTABLE = ['effective_from' => 'effective_from', 'created_at' => 'created_at'];

    public function index(IndexMaterialSelectionPolicyRequest $request): AnonymousResourceCollection
    {
        $query = MaterialSelectionPolicy::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        foreach (['scope_type', 'scope_id', 'status'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }
        $this->applySort($query, $request, self::SORTABLE, '-effective_from');

        $page = $query->paginate($request->integer('per_page', 25));
        $this->nameScopes($page->getCollection()->all());

        return MaterialSelectionPolicyResource::collection($page);
    }

    public function store(StoreMaterialSelectionPolicyRequest $request, SetMaterialSelectionPolicy $set): JsonResponse
    {
        $row = $set(
            MaterialSelectionScope::from($request->string('scope_type')->toString()),
            $request->filled('scope_id') ? $request->integer('scope_id') : null,
            $request->filled('strategy') ? MaterialSelectionStrategy::from($request->string('strategy')->toString()) : null,
            $request->string('reason')->toString(),
            (int) $request->user()->id,
        );
        $this->nameScopes([$row]);

        return (new MaterialSelectionPolicyResource($row->load('changedBy')))->response()->setStatusCode(201);
    }

    /** @param  list<MaterialSelectionPolicy>  $rows */
    private function nameScopes(array $rows): void
    {
        $itemIds = $categoryIds = [];
        foreach ($rows as $row) {
            if ($row->scope_type === MaterialSelectionScope::Item) {
                $itemIds[] = $row->scope_id;
            } elseif ($row->scope_type === MaterialSelectionScope::ItemCategory) {
                $categoryIds[] = $row->scope_id;
            }
        }
        $items = Item::query()->whereIn('id', $itemIds)->get()->keyBy('id');
        $categories = Category::query()->whereIn('id', $categoryIds)->get()->keyBy('id');
        foreach ($rows as $row) {
            $name = match ($row->scope_type) {
                MaterialSelectionScope::Company => 'Whole company',
                MaterialSelectionScope::Item => ($item = $items->get($row->scope_id)) === null ? null : "{$item->code} — {$item->name}",
                MaterialSelectionScope::ItemCategory => $categories->get($row->scope_id)?->name,
            };
            $row->setAttribute('scope_name', $name);
        }
    }
}
