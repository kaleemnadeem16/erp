<?php

declare(strict_types=1);

namespace App\Modules\Company\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Company\Http\Requests\IndexCompanyChangeRequest;
use App\Modules\Company\Http\Resources\CompanyChangeResource;
use App\Modules\Company\Models\CompanyChange;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Read-only history of company changes; needs `company.history.view` because who changed what, and why, is more sensitive than the values. */
class CompanyChangeController extends Controller
{
    public function index(IndexCompanyChangeRequest $request): AnonymousResourceCollection
    {
        $query = CompanyChange::query()->with('changedBy')->orderByDesc('changed_at')->orderByDesc('id');
        if ($request->filled('filter.section')) {
            $query->where('section', $request->string('filter.section')->toString());
        }

        return CompanyChangeResource::collection($query->paginate($request->integer('per_page', 25)));
    }
}
