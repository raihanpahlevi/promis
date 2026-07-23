<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Route;

/**
 * Registers routes/kantor.php's routes at runtime with the same middleware
 * stack routes/web.php wires it with, so feature tests have real HTTP routes
 * to hit — mirrors tests/Concerns/RegistersUserRoutes.php /
 * RegistersPoiRoutes.php (same convention across this whole test suite).
 */
trait RegistersKantorRoutes
{
    protected function registerKantorRoutes(): void
    {
        Route::group([
            'middleware' => ['web', 'auth', 'force.password.change', 'active.kantor'],
        ], base_path('routes/kantor.php'));

        Route::getRoutes()->refreshNameLookups();
    }
}
