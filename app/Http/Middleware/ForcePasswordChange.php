<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every page except the change-password screen (and logout) until a
 * user with force_password_change=true has set a real password. This is what
 * makes the NPP-as-default-password scheme (PRD §3b) safe to use — the guard
 * itself must not be skippable, not just the UI hiding other links.
 */
class ForcePasswordChange
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->force_password_change
            && ! $request->routeIs('ganti-password*')
            && ! $request->routeIs('logout')) {
            return redirect()->route('ganti-password');
        }

        return $next($request);
    }
}
