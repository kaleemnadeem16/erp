<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Numbering\Http\Requests\NumberingViewRequest;
use App\Modules\Numbering\Http\Resources\NumberingArrayResource;
use App\Modules\Numbering\Services\NumberingOverview;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Settings → Document Numbering: every registered document type with its series and a live example. */
class NumberingDocumentTypeController extends Controller
{
    public function index(NumberingViewRequest $request, NumberingOverview $overview): AnonymousResourceCollection
    {
        return NumberingArrayResource::collection($overview->types($request->user()));
    }

    /** The company's constants, the engine's built-in variables and the time zone dates are worked out in. */
    public function context(NumberingViewRequest $request, NumberingOverview $overview): NumberingArrayResource
    {
        return new NumberingArrayResource($overview->context());
    }
}
