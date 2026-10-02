<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Numbering\Http\Requests\PreviewNumberingRequest;
use App\Modules\Numbering\Http\Resources\NumberingArrayResource;
use App\Modules\Numbering\Services\NumberingPreview;
use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\ScopePeriod;
use Carbon\CarbonImmutable;

/** The tester. Read-only: it never takes a number, so it is a POST only because the configuration is too large for a query string. */
class NumberingPreviewController extends Controller
{
    public function preview(PreviewNumberingRequest $request, NumberingPreview $preview): NumberingArrayResource
    {
        return new NumberingArrayResource($preview->preview(
            $request->string('document_type')->toString(),
            $request->string('template')->toString(),
            CounterScope::of(ScopePeriod::from($request->string('scope_period')->toString()), (array) $request->input('scope_dimensions', [])),
            Continuity::from($request->string('continuity')->toString()),
            $request->filled('series_code') ? $request->string('series_code')->toString() : null,
            $request->filled('at') ? CarbonImmutable::parse($request->string('at')->toString()) : null,
        ));
    }
}
