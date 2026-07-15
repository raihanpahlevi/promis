<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side "kantor aktif" guard — but, confirmed against the real v1
 * dashboard source, this session-locked single-kantor requirement only
 * applies to `sales` (they create records that must be tagged to one
 * specific kantor). `admin_final` users assigned to several kantor are
 * NOT forced through pilih-kantor: they browse an aggregate "ALL of their
 * kantor" view by default, narrowed via a per-request ?kantor= filter that
 * each module validates against user_kantor itself (Tahap 3+), not via
 * session state.
 *
 * The old system trusted whatever kantor was picked client-side (the
 * `pilih_kantor.php` bug referenced in the PRD) — here the active kantor is
 * re-validated against `user_kantor` on every request, not just at login.
 */
class EnsureActiveKantor
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== User::ROLE_SALES) {
            return $next($request);
        }

        $kantorIds = $user->kantor()->pluck('kantor.id');

        if ($kantorIds->isEmpty()) {
            abort(403, 'Akun Anda belum ditugaskan ke kantor manapun. Hubungi admin.');
        }

        if ($kantorIds->count() === 1) {
            session(['active_kantor_id' => $kantorIds->first()]);

            return $next($request);
        }

        $activeKantorId = session('active_kantor_id');

        if (! $activeKantorId || ! $kantorIds->contains($activeKantorId)) {
            if ($request->routeIs('pilih-kantor*')) {
                return $next($request);
            }

            return redirect()->route('pilih-kantor');
        }

        return $next($request);
    }
}
