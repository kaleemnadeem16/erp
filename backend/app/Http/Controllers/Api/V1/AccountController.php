<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateAccountPasswordRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service profile and password management for the currently authenticated
 * user — distinct from AuthController, which handles establishing (and ending)
 * the session/token itself.
 */
class AccountController extends Controller
{
    public function show(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user);
    }

    public function update(UpdateAccountRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $user->update($request->validated());

        return new UserResource($user);
    }

    public function updatePassword(UpdateAccountPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['password' => $request->validated('password')])->save();

        return response()->json(status: 204);
    }
}
