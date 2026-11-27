<?php

namespace App\Http\Middleware;

use App\Models\Ambassador;
use App\Models\Amicale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps ambassador and amicale accounts out of the back office: its routes answer on any host, so a partner
 * logged in on their own domain must not be able to reach them.
 */
class RejectExternalPartnerAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->hasAnyRole([Ambassador::ACCOUNT_ROLE_NAME, Amicale::ACCOUNT_ROLE_NAME]), 403);

        return $next($request);
    }
}
