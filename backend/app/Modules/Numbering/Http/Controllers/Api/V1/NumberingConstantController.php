<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Numbering\Actions\SaveConstant;
use App\Modules\Numbering\Http\Requests\NumberingViewRequest;
use App\Modules\Numbering\Http\Requests\StoreNumberingConstantRequest;
use App\Modules\Numbering\Http\Resources\NumberingArrayResource;
use App\Modules\Numbering\Models\NumberingConstant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Company constants ({REGION}=NORTH): the list is the history, newest first; a change is a new row. */
class NumberingConstantController extends Controller
{
    public function index(NumberingViewRequest $request): AnonymousResourceCollection
    {
        return NumberingArrayResource::collection(
            NumberingConstant::query()->orderByDesc('id')->get()->map(fn (NumberingConstant $c): array => $this->row($c))->all()
        );
    }

    public function store(StoreNumberingConstantRequest $request, SaveConstant $save): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $row = $save($request->string('code')->toString(), $request->input('value') === null ? null : $request->string('value')->toString(), $request->string('reason')->toString(), $user);

        return (new NumberingArrayResource($this->row($row)))->response()->setStatusCode(201);
    }

    /** @return array<string, mixed> */
    private function row(NumberingConstant $c): array
    {
        return [
            'id' => $c->id, 'code' => $c->code, 'value' => $c->value, 'status' => $c->status, 'reason' => $c->reason,
            'effective_from' => $c->effective_from->toIso8601String(), 'effective_to' => $c->effective_to?->toIso8601String(),
            'changed_by_name' => User::query()->whereKey($c->changed_by)->value('name'),
        ];
    }
}
