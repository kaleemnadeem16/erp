<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Sales\Http\Requests\SalesOwnerLookupRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class SalesOwnerController extends Controller
{
    /**
     * The active members who can be made responsible (ADR 0106): id and name only. Sales users choose an owner without needing member administration,
     * and learn nothing else about anyone.
     */
    public function index(SalesOwnerLookupRequest $request): JsonResponse
    {
        $query = User::query()->where('status', MemberStatus::Active->value)->orderBy('name')->limit(25);
        if ($request->filled('filter.id')) {
            $query->whereKey($request->integer('filter.id'));
        }
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%"));
        }

        return response()->json(['data' => $query->get(['id', 'name'])->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()]);
    }
}
