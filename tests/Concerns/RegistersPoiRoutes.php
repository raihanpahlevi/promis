<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Route;

/**
 * routes/poi.php is intentionally NOT wired into routes/web.php yet (per the
 * module brief — a separate integration step wires it centrally alongside
 * the parallel "Modul Kunjungan" work, inside the same auth / force-password
 * / active-kantor middleware stack used for /dashboard in routes/web.php).
 *
 * Feature tests need real HTTP routes to hit, so this registers the exact
 * same middleware stack at runtime, without touching routes/web.php.
 */
trait RegistersPoiRoutes
{
    protected function registerPoiRoutes(): void
    {
        Route::group([
            'middleware' => ['web', 'auth', 'force.password.change', 'active.kantor'],
        ], base_path('routes/poi.php'));

        // Route::get(...)->name(...) only sets the name on the Route object;
        // the name->route lookup index used by the route() helper is built
        // when the collection is first booted (normally right after routes/web.php
        // finishes loading). Since these routes are added after that point,
        // refresh the index manually so route('poi.*') resolves in tests.
        Route::getRoutes()->refreshNameLookups();
    }
}
