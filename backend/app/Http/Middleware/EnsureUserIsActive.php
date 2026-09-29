<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Administration\Support\MemberStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && $request->user()->status !== MemberStatus::Active) {
            abort(403, 'This account is not active.');
        }

        return $next($request);
    }
}
