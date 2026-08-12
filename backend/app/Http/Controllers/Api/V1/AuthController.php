<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Cookie session auth for the SPA, bearer tokens for mobile — both via Sanctum,
 * chosen by whether the request carries device_name. See
 * docs/decisions/0005-sanctum-authentication.md.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        // Auth::attempt() builds a raw WHERE on the credentials array, bypassing the
        // User model's email-lowercasing mutator entirely — normalise here too, or
        // login becomes case-sensitive despite storage never being. See
        // docs/architecture/database.md.
        $credentials = $request->only('email', 'password');
        $credentials['email'] = strtolower($credentials['email']);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($request->filled('device_name')) {
            $token = $user->createToken($request->string('device_name')->toString())->plainTextToken;

            return (new UserResource($user))
                ->additional(['meta' => ['token' => $token]])
                ->response();
        }

        $request->session()->regenerate();

        return (new UserResource($user))->response();
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        // currentAccessToken() returns a TransientToken (no delete()) for session/web
        // auth, despite the PHPDoc only naming PersonalAccessToken.
        // @phpstan-ignore function.alreadyNarrowedType
        if ($token && method_exists($token, 'delete')) {
            // Bearer-token (mobile) request: revoke only this token.
            $token->delete();
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(status: 204);
    }

    public function user(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink(['email' => strtolower($request->string('email')->toString())]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => __($status)]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            [
                ...$request->only('password', 'password_confirmation', 'token'),
                'email' => strtolower($request->string('email')->toString()),
            ],
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => __($status)]);
    }
}
