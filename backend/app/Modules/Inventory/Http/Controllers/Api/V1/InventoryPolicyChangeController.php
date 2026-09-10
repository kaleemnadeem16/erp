<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\InventoryPolicyChange\IndexInventoryPolicyChangeRequest;
use App\Modules\Inventory\Http\Resources\InventoryPolicyChangeResource;
use App\Modules\Inventory\Models\InventoryPolicyChange;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only: every row is written by
 * App\Modules\Inventory\Actions\UpdateInventorySettings, one per changed
 * policy group, never mutated afterward (DB-trigger immutable). Surfaces the
 * audit trail ADR 0047 requires alongside the governed settings themselves —
 * see InventorySettingsController for the current values.
 *
 * Unlike ordinary reference-data lists (stock-layers, avco-pools), reading
 * this audit trail requires its own `inventory.policy-history.view`
 * permission (see IndexInventoryPolicyChangeRequest) — who changed a policy,
 * why and when is more sensitive than the settings themselves, which any
 * user who needs them can already see.
 */
class InventoryPolicyChangeController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = ['changedBy' => 'changedBy'];

    /** @var array<string, string> */
    private const SORTABLE = ['changed_at' => 'changed_at'];

    public function index(IndexInventoryPolicyChangeRequest $request): AnonymousResourceCollection
    {
        $query = InventoryPolicyChange::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);

        if ($request->filled('filter.policy')) {
            $query->where('policy', $request->string('filter.policy')->toString());
        }

        $this->applySort($query, $request, self::SORTABLE, '-changed_at');

        return InventoryPolicyChangeResource::collection($query->paginate($request->integer('per_page', 25)));
    }
}
