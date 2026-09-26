<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\OverheadPolicy;

use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The unrestricted "what applies right now" read every estimator needs —
 * no special permission, same as ResolveApplicationRate/ResolveFormulation.
 * Basis params are optional: omitting all of them still resolves and
 * returns which policy/scope won, just with overhead_amount computed
 * against zero (there is no assembly_components data to read from until
 * Step 3 — see OverheadCostBasis).
 */
class ResolveOverheadPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assembly_id' => ['required', 'integer', CompanyScoped::exists('assemblies')],
            'material_cost' => ['sometimes', 'decimal:0,6', 'gte:0'],
            'labour_cost' => ['sometimes', 'decimal:0,6', 'gte:0'],
            'plant_cost' => ['sometimes', 'decimal:0,6', 'gte:0'],
            'subcontract_cost' => ['sometimes', 'decimal:0,6', 'gte:0'],
            'labour_hours' => ['sometimes', 'decimal:0,6', 'gte:0'],
            'work_qty' => ['sometimes', 'decimal:0,6', 'gte:0'],
        ];
    }
}
