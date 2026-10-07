<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\SuggestPackageDraw;
use App\Modules\Inventory\Http\Requests\PackageStock\SuggestPackageDrawRequest;
use Illuminate\Http\JsonResponse;

class PackageDrawController extends Controller
{
    /** Proposes how to take a wanted quantity from the current packages (loose first, then fewest whole packages, then one opened). Posts nothing. */
    public function suggest(SuggestPackageDrawRequest $request, SuggestPackageDraw $suggest): JsonResponse
    {
        return response()->json(['data' => $suggest($request->integer('item_id'), $request->integer('location_id'),
            $request->filled('batch_id') ? $request->integer('batch_id') : null, (string) $request->input('quantity'), $request->user())]);
    }
}
