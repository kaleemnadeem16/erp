<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The devices signed in to the caller's own account (bearer tokens, for example a phone) and the right to sign one out. Always the
 * caller's own: the query starts from their tokens, so another person's device is a 404, not a 403 that confirms it exists. Browser
 * sessions are not devices and are not listed. Core identity, like AccountController (docs/decisions/0088-*.md, Q36).
 */
class DeviceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $devices = $user->tokens()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('created_at')
            ->get();

        return DeviceResource::collection($devices);
    }

    public function destroy(Request $request, int $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->tokens()->findOrFail($device)->delete();

        return response()->json(status: 204);
    }
}
