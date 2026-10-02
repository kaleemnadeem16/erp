<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Numbering\Actions\CreateSeries;
use App\Modules\Numbering\Actions\CreateSeriesRule;
use App\Modules\Numbering\Actions\SaveSeriesVersion;
use App\Modules\Numbering\Http\Requests\NumberingViewRequest;
use App\Modules\Numbering\Http\Requests\StoreNumberingRuleRequest;
use App\Modules\Numbering\Http\Requests\StoreNumberingSeriesRequest;
use App\Modules\Numbering\Http\Requests\StoreNumberingVersionRequest;
use App\Modules\Numbering\Http\Resources\NumberingArrayResource;
use App\Modules\Numbering\Models\NumberingCounter;
use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Models\NumberingSeriesRule;
use App\Modules\Numbering\Models\NumberingSeriesVersion;
use App\Modules\Numbering\Services\CounterStore;
use App\Modules\Numbering\Services\NumberingOverview;
use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\ScopePeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** A document type's series: create one, add a governed version, and read its history, counters and routing rules. */
class NumberingSeriesController extends Controller
{
    public function store(StoreNumberingSeriesRequest $request, CreateSeries $create, NumberingOverview $overview): JsonResponse
    {
        $series = $create(
            $request->string('document_type')->toString(), $request->string('code')->toString(), $request->string('name')->toString(),
            $request->string('template')->toString(),
            CounterScope::of(ScopePeriod::from($request->string('scope_period')->toString()), (array) $request->input('scope_dimensions', [])),
            Continuity::from($request->string('continuity')->toString()), $request->boolean('is_selectable'),
            $request->string('reason')->toString(), $this->actor($request),
        );

        return (new NumberingArrayResource($overview->series($series)))->response()->setStatusCode(201);
    }

    public function versions(NumberingViewRequest $request, NumberingSeries $series): AnonymousResourceCollection
    {
        return NumberingArrayResource::collection(
            NumberingSeriesVersion::query()->where('series_id', $series->id)->orderByDesc('id')->get()->map(fn (NumberingSeriesVersion $v): array => [
                'id' => $v->id, 'template' => $v->template, 'scope_period' => $v->scope_period, 'scope_dimensions' => $v->scope_dimensions ?? [],
                'scope_text' => $v->scope()->describe(), 'continuity' => $v->continuity->value, 'status' => $v->status, 'reason' => $v->reason,
                'effective_from' => $v->effective_from->toIso8601String(), 'effective_to' => $v->effective_to?->toIso8601String(),
                'changed_by' => $v->changed_by,
                'changed_by_name' => $v->changed_by === null ? 'Carried over by the system' : User::query()->whereKey($v->changed_by)->value('name'),
            ])->all()
        );
    }

    public function storeVersion(StoreNumberingVersionRequest $request, NumberingSeries $series, SaveSeriesVersion $save, NumberingOverview $overview): JsonResponse
    {
        $save(
            $series, $request->string('template')->toString(),
            CounterScope::of(ScopePeriod::from($request->string('scope_period')->toString()), (array) $request->input('scope_dimensions', [])),
            Continuity::from($request->string('continuity')->toString()), $request->string('reason')->toString(), $this->actor($request),
        );

        return (new NumberingArrayResource($overview->series($series->refresh())))->response()->setStatusCode(201);
    }

    public function counters(NumberingViewRequest $request, NumberingSeries $series, CounterStore $store): AnonymousResourceCollection
    {
        return NumberingArrayResource::collection(
            NumberingCounter::query()->where('series_id', $series->id)->orderBy('counter_key')->get()->map(fn (NumberingCounter $c): array => [
                'id' => $c->id, 'counter_key' => $c->counter_key, 'allocator' => $c->allocator,
                'next_number' => $store->strategy($c)->peek($c), 'updated_at' => $c->updated_at?->toIso8601String(),
            ])->all()
        );
    }

    public function rules(NumberingViewRequest $request, NumberingSeries $series): AnonymousResourceCollection
    {
        return NumberingArrayResource::collection(
            NumberingSeriesRule::query()->where('series_id', $series->id)->where('status', 'ACTIVE')->orderBy('priority')->get()->map(fn (NumberingSeriesRule $r): array => [
                'id' => $r->id, 'priority' => $r->priority, 'conditions' => $r->conditions,
            ])->all()
        );
    }

    public function storeRule(StoreNumberingRuleRequest $request, NumberingSeries $series, CreateSeriesRule $create): JsonResponse
    {
        $rule = $create($series, $request->integer('priority'), (array) $request->input('conditions'), $this->actor($request));

        return (new NumberingArrayResource(['id' => $rule->id, 'priority' => $rule->priority, 'conditions' => $rule->conditions]))->response()->setStatusCode(201);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
