<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Route;

/**
 * routes/user.php is intentionally NOT wired into routes/web.php yet (per the
 * module brief — wiring happens centrally alongside the parallel POI/Kunjungan
 * work). Feature tests need real HTTP routes to hit, so this registers the
 * exact same middleware stack at runtime, without touching routes/web.php.
 * Mirrors tests/Concerns/RegistersPoiRoutes.php.
 */
trait RegistersUserRoutes
{
    protected function registerUserRoutes(): void
    {
        Route::group([
            'middleware' => ['web', 'auth', 'force.password.change', 'active.kantor'],
        ], base_path('routes/user.php'));

        // Route::get(...)->name(...) only sets the name on the Route object;
        // the name->route lookup index used by the route() helper is built
        // when the collection is first booted (normally right after routes/web.php
        // finishes loading). Since these routes are added after that point,
        // refresh the index manually so route('user.*') resolves in tests.
        Route::getRoutes()->refreshNameLookups();
    }
}
