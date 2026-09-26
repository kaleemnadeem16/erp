<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Estimating\Actions\PreviewAssemblyMaterials;
use App\Modules\Estimating\Http\Requests\MaterialPreview\PreviewMaterialRequest;
use App\Modules\Estimating\Models\Assembly;
use Illuminate\Http\JsonResponse;

class MaterialPreviewController extends Controller
{
    public function __invoke(
        PreviewMaterialRequest $request,
        Assembly $assembly,
        PreviewAssemblyMaterials $preview,
    ): JsonResponse {
        return response()->json([
            'data' => $preview(
                $assembly,
                $request->validated(),
                $request->user()?->can('estimating.internal_cost.view') === true,
            ),
        ]);
    }
}
