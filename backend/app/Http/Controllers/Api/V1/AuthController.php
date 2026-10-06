<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\IssueDeviceToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Support\Auth\SignInRequirements;
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
    public function login(LoginRequest $request, IssueDeviceToken $issueDeviceToken, SignInRequirements $requirements): JsonResponse
    {
        // The switch for signing in with a device_name at all (config/signin.php): off stops any new bearer token without a release.
        if ($request->filled('device_name') && ! config('signin.bearer_sign_in_enabled')) {
            throw ValidationException::withMessages(['device_name' => ['Signing in with a device is turned off.']]);
        }

        // Auth::attempt() builds a raw WHERE on the credentials array, bypassing the
        // User model's email-lowercasing mutator entirely — normalise here too, or
        // login becomes case-sensitive despite storage never being. See
        // docs/architecture/database.md.
        $credentials = $request->only('email', 'password');
        $credentials['email'] = strtolower($credentials['email']);
        $credentials['status'] = MemberStatus::Active->value;

        if (! Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        // A correct password may not be enough (a second factor, a confirmed device — Q37). Nothing is registered today; when something
        // is, the first unmet requirement stops the sign-in before any session or token exists and before it is recorded as a login.
        $challenge = $requirements->firstUnmet($user, $request);
        if ($challenge !== null) {
            Auth::guard('web')->logout();

            return response()->json(['message' => $challenge->message, 'challenge' => ['type' => $challenge->type, 'data' => $challenge->data]], 401);
        }

        // A sign-in is not a profile/access edit; keep updated_at meaningful.
        User::withoutTimestamps(fn () => $user->forceFill(['last_login_at' => now()])->save());

        if ($request->filled('device_name')) {
            $token = $issueDeviceToken($user, $request->string('device_name')->toString(), $request)->plainTextToken;

            return (new UserResource($user))
                ->additional(['meta' => ['token' => $token]])
                ->response();
        }

        $request->session()->regenerate();
        $request->session()->put('credential_version', $user->credential_version);

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
        // The same answer whether or not the address belongs to an account (and whether or not the broker throttled it), so this
        // endpoint cannot be used to find out who has an account here. The link, if any, goes to the mailbox.
        Password::sendResetLink(['email' => strtolower($request->string('email')->toString())]);

        return response()->json(['message' => 'If that email belongs to an account, a password reset link has been sent to it.']);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            [
                ...$request->only('password', 'password_confirmation', 'token'),
                'email' => strtolower($request->string('email')->toString()),
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'status' => $user->status === MemberStatus::Invited ? MemberStatus::Active : $user->status,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'must_change_password' => false,
                    'credential_version' => $user->credential_version + 1,
                ])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => __($status)]);
    }
}
