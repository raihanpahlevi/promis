<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache for the Dashboard's POI aggregates.
 *
 * Those aggregates (per Ring Area, per Kategori, per Sub Kategori) sweep the
 * whole poi table — ~170k rows in production — and they were recomputed from
 * scratch on every page load, for every user. Measured on production
 * 2026-08-02: 17 queries totalling ~1s per load, of which the five heaviest
 * accounted for 765ms. Twenty people opening the Dashboard at once made the
 * database repeat identical work twenty times, and the median response went
 * from ~2s to 8.4s.
 *
 * The numbers are the same for everyone looking at the same kantor scope and
 * only move when POI data does, so they cache cleanly. Entries live 5 minutes.
 *
 * Invalidation is version-based rather than key-enumerating: flush() bumps a
 * counter that every key embeds, so one write retires every cached scope at
 * once. That matters because the scopes are combinatorial (any Area x Cluster
 * x Cabang selection is its own key) and there is no way to list them. The
 * import job calls flush() when it finishes, so a bulk import shows up
 * immediately instead of waiting out the TTL — see App\Jobs\ProcessImport.
 */
class DashboardCache
{
    private const TTL_SECONDS = 300;

    private const VERSION_KEY = 'dashboard:aggregate-version';

    /**
     * @template T
     *
     * @param  array<mixed>  $scope  everything that changes the result
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(string $name, array $scope, Closure $compute)
    {
        return Cache::remember($this->key($name, $scope), self::TTL_SECONDS, $compute);
    }

    /**
     * Retires every cached aggregate, for every kantor scope, in one write.
     */
    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, $this->version() + 1);
    }

    private function key(string $name, array $scope): string
    {
        // Kantor ids are sorted so the same selection made in a different
        // order shares one entry rather than warming a second copy.
        sort($scope);

        return 'dashboard:v'.$this->version().':'.$name.':'.md5(serialize($scope));
    }

    private function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }
}
