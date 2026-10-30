<?php

namespace App\Http\Middleware;

use App\Models\Ambassador;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps ambassador accounts out of the back office: its routes answer on any host, so an
 * ambassador logged in on their own domain must not be able to reach them.
 */
class RejectAmbassadorAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->hasRole(Ambassador::ACCOUNT_ROLE_NAME), 403);

        return $next($request);
    }
}
