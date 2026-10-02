<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Numbering\Actions\RaiseCounter;
use App\Modules\Numbering\Http\Requests\RaiseNumberingCounterRequest;
use App\Modules\Numbering\Http\Resources\NumberingArrayResource;
use App\Modules\Numbering\Models\NumberingCounter;
use App\Modules\Numbering\Services\CounterStore;

/** Raising a counter — never lowering it (ADR 0079 §16). */
class NumberingCounterController extends Controller
{
    public function raise(RaiseNumberingCounterRequest $request, NumberingCounter $counter, RaiseCounter $raise, CounterStore $store): NumberingArrayResource
    {
        /** @var User $user */
        $user = $request->user();
        $adjustment = $raise($counter, $request->integer('next'), $request->string('reason')->toString(), $user);
        $counter = $counter->refresh();

        return new NumberingArrayResource([
            'id' => $counter->id, 'counter_key' => $counter->counter_key, 'allocator' => $counter->allocator,
            'next_number' => $store->strategy($counter)->peek($counter), 'from_value' => $adjustment->from_value, 'to_value' => $adjustment->to_value,
        ]);
    }
}
