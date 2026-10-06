<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Auth\DeviceTokenPolicy;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;

/**
 * The only place a bearer token is created. It asks DeviceTokenPolicy for the lifetime and abilities, so every token expires and none
 * can outlive the policy, and it records how the device authenticated so a later rule can reason about it.
 */
final class IssueDeviceToken
{
    public function __construct(private readonly DeviceTokenPolicy $policy) {}

    /** @param  list<string>  $authMethods  how the person proved who they are, for example ['password']; a second factor adds to it */
    public function __invoke(User $user, string $deviceName, Request $request, array $authMethods = ['password']): NewAccessToken
    {
        $rules = $this->policy->rulesFor($user);
        $token = $user->createToken($deviceName, $rules->abilities, now()->addMinutes($rules->lifetimeMinutes));
        $token->accessToken->forceFill(['meta' => [
            'auth_methods' => $authMethods,
            'authenticated_at' => now()->toIso8601String(),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]])->save();

        return $token;
    }
}
