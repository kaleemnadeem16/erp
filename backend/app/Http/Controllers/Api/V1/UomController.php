<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Uom\IndexUomRequest;
use App\Http\Resources\UomResource;
use App\Models\Uom;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only: UoMs are reference data seeded/managed elsewhere. Exists so
 * dependent forms (Item's stock UoM picker) have a resource to query rather
 * than hardcoding options — see docs/domain/units-of-measure.md.
 */
class UomController extends Controller
{
    use AppliesApiQuery;

    private const SORTABLE = ['code' => 'code', 'name' => 'name', 'created_at' => 'created_at'];

    public function index(IndexUomRequest $request): AnonymousResourceCollection
    {
        $query = Uom::query();
        if ($request->filled('filter.dimension')) {
            $query->where('dimension', $request->input('filter.dimension'));
        }
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }
        $this->applySort($query, $request, self::SORTABLE, 'code');

        return UomResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(Request $request, Uom $uom): UomResource
    {
        return new UomResource($uom);
    }
}
