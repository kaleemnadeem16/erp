<?php

declare(strict_types=1);

namespace App\Modules\Company\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Company\Actions\UpdateCompany;
use App\Modules\Company\Http\Requests\UpdateCompanyRequest;
use App\Modules\Company\Http\Resources\CompanyResource;
use App\Support\CompanyContext;

/** The current company's profile and company-wide settings (ADR 0081). Readable by any signed-in user; changes are field-permissioned. */
class CompanyController extends Controller
{
    public function show(CompanyContext $context): CompanyResource
    {
        return new CompanyResource(Company::query()->with('baseCurrency')->findOrFail($context->id()));
    }

    public function update(UpdateCompanyRequest $request, UpdateCompany $update): CompanyResource
    {
        $company = $update($request->safe()->except('reason'), $request->user(), $request->validated('reason'));

        return new CompanyResource($company->load('baseCurrency'));
    }
}
