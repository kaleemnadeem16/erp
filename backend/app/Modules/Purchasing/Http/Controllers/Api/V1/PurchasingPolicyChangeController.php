<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Http\Requests\PurchasingPolicyChange\IndexPurchasingPolicyChangeRequest;
use App\Modules\Purchasing\Http\Resources\PurchasingPolicyChangeResource;
use App\Modules\Purchasing\Models\PurchasingPolicyChange;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only: every row is written by
 * App\Modules\Purchasing\Actions\UpdatePurchasingSettings, one per changed
 * policy field group, never mutated afterward (DB-trigger immutable).
 * Surfaces the audit trail alongside the governed settings themselves — see
 * PurchasingSettingsController for the current values.
 *
 * Unlike ordinary reference-data lists (stock-layers, avco-pools), reading
 * this audit trail requires its own `purchasing.policy-history.view`
 * permission (see IndexPurchasingPolicyChangeRequest) — who changed a policy,
 * why and when is more sensitive than the settings themselves, which any
 * user who needs them can already see.
 */
class PurchasingPolicyChangeController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = ['changedBy' => 'changedBy'];

    /** @var array<string, string> */
    private const SORTABLE = ['changed_at' => 'changed_at'];

    public function index(IndexPurchasingPolicyChangeRequest $request): AnonymousResourceCollection
    {
        $query = PurchasingPolicyChange::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);

        if ($request->filled('filter.policy')) {
            $query->where('policy', $request->string('filter.policy')->toString());
        }

        $this->applySort($query, $request, self::SORTABLE, '-changed_at');

        return PurchasingPolicyChangeResource::collection($query->paginate($request->integer('per_page', 25)));
    }
}
