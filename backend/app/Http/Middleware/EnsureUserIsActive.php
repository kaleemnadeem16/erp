<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Administration\Support\MemberStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // These identity endpoints must remain reachable from a stale session so
        // a user can sign in again or recover/close their account session.
        if ($request->is('api/v1/login', 'api/v1/logout', 'api/v1/forgot-password', 'api/v1/reset-password')) {
            return $next($request);
        }
        if ($request->user() !== null && $request->user()->status !== MemberStatus::Active) {
            abort(403, 'This account is not active.');
        }

        if ($request->user() !== null) {
            $version = $request->hasSession() ? (int) $request->session()->get('credential_version', 0) : 0;
            if (Auth::guard('web')->check() && $version !== (int) $request->user()->credential_version) {
                abort(401, 'Please sign in again after your password was changed.');
            }
            if ($request->user()->must_change_password && ! $request->is('api/v1/user', 'api/v1/logout', 'api/v1/account/password')) {
                abort(403, 'Change your temporary password before using the application.');
            }
        }

        return $next($request);
    }
}
