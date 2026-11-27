<?php

namespace App\Http\Middleware;

use App\Models\Amicale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the amicale admin domain: only user accounts carrying the amicale role may pass, and an amicale
 * deactivated after its admin signed in is signed out with the reason.
 */
class EnsureUserIsAmicaleAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->hasRole(Amicale::ACCOUNT_ROLE_NAME), 403);

        $rejectionMessage = $user->amicale?->accessRejectionMessage();

        if ($rejectionMessage !== null) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('login_rejection', $rejectionMessage);
        }

        return $next($request);
    }
}
