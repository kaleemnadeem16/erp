<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Estimating\Http\Requests\MaterialVariancePolicy\ShowMaterialVarianceCapabilitiesRequest;
use App\Modules\Estimating\Support\VarianceApprovalAvailability;
use Illuminate\Http\JsonResponse;

/** What the variance screens may offer today, so they never show a choice the server would refuse. */
class MaterialVarianceCapabilitiesController extends Controller
{
    public function __invoke(ShowMaterialVarianceCapabilitiesRequest $request, VarianceApprovalAvailability $approvals): JsonResponse
    {
        return response()->json(['data' => ['approval_required_available' => $approvals->enabled()]]);
    }
}
