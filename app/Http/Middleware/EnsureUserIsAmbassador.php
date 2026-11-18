<?php

namespace App\Http\Middleware;

use App\Models\Ambassador;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the ambassador domain: only user accounts carrying the ambassador role may pass, and an ambassador
 * suspended or whose contract expired after they signed in is signed out with the reason.
 */
class EnsureUserIsAmbassador
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->hasRole(Ambassador::ACCOUNT_ROLE_NAME), 403);

        $rejectionMessage = $user->ambassador?->accessRejectionMessage();

        if ($rejectionMessage !== null) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('login_rejection', $rejectionMessage);
        }

        return $next($request);
    }
}
