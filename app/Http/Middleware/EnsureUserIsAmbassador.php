<?php

namespace App\Http\Middleware;

use App\Models\Ambassador;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the ambassador domain: only user accounts carrying the ambassador role may pass.
 */
class EnsureUserIsAmbassador
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->hasRole(Ambassador::ACCOUNT_ROLE_NAME), 403);

        return $next($request);
    }
}
