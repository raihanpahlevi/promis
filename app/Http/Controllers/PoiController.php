<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NarrowsKantorByAreaCluster;
use App\Models\Kantor;
use App\Models\Poi;
use App\Models\PoiReopenLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Manual CRUD for the `poi` table (PRD "Modul Data POI"). Every write goes
 * through Eloquent (Poi::create()/update()/PoiReopenLog::create()) so
 * PoiObserver keeps `dashboard_summary` in sync — never touch `poi` via
 * DB::table() or withoutEvents() here.
 *
 * Authorization is enforced in this controller, not just by hiding links:
 * `admin` may touch any kantor's POI, `admin_final` only kantor they're
 * assigned to (checked both on the POI's current kantor_id and, on update,
 * the incoming kantor_id), `sales` is read-only and scoped to their single
 * active kantor (session `active_kantor_id`, set by EnsureActiveKantor).
 */
class PoiController extends Controller
{
    use NarrowsKantorByAreaCluster;

    public function index(Request $request): View
    {
        $user = $request->user();

        $query = Poi::query()->with('kantor');
        $scope = $this->scopeIndexQuery($query, $user, $request);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // The POI's own Ring Area (Poi::AREA_OPTIONS, "Ring 1".."Ring 4") —
        // query param renamed `area` -> `ring_area` (2026-07-23) to free up
        // `area` for the NEW Cabang-region filter below ($scope), which is a
        // completely different concept (see PoiImport's class docblock for
        // the same Ring Area vs Area distinction on the import side).
        if ($request->filled('ring_area')) {
            $query->where('area', $request->input('ring_area'));
        }

        if ($request->filled('sektor')) {
            $query->where('sektor', $request->input('sektor'));
        }

        if ($request->filled('status_mitra')) {
            $query->where('status_mitra', $request->input('status_mitra'));
        }

        if ($request->filled('q')) {
            $search = $request->input('q');
            $query->where(function ($q) use ($search) {
                $q->where('nama_poi', 'like', "%{$search}%")
                    ->orWhere('alamat', 'like', "%{$search}%")
                    ->orWhere('pic', 'like', "%{$search}%");
            });
        }

        $pois = $query->orderByDesc('id')->paginate(15)->withQueryString();

        return view('poi.index', [
            'pois' => $pois,
            'kantorOptions' => $scope['kantorOptions'],
            'kantorAreaOptions' => $scope['areaOptions'],
            'selectedKantorArea' => $scope['selectedArea'],
            'kantorClusterOptions' => $scope['clusterOptions'],
            'selectedKantorCluster' => $scope['selectedCluster'],
            'sektorOptions' => Poi::SEKTOR_OPTIONS,
            'ringAreaOptions' => Poi::AREA_OPTIONS,
            'statusMitraOptions' => Poi::STATUS_MITRA_OPTIONS,
            'canManage' => $user->isAdmin() || $user->isAdminFinal(),
            'filters' => $request->only(['kantor', 'area', 'cluster', 'status', 'ring_area', 'sektor', 'status_mitra', 'q']),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();

        return view('poi.create', [
            'kantorOptions' => $this->kantorOptionsFor($user),
            'sektorOptions' => Poi::SEKTOR_OPTIONS,
            'areaOptions' => Poi::AREA_OPTIONS,
            'statusMitraOptions' => Poi::STATUS_MITRA_OPTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $this->validatePoi($request);

        $this->ensureKantorAccess($user, (int) $data['kantor_id']);

        // 'status' is set explicitly (not left to the DB column default):
        // PoiObserver::created() reads $poi->status from the in-memory model
        // right after insert, and Eloquent never backfills attributes from
        // DB-side defaults — leaving it unset makes the observer's "was this
        // created aktif?" check silently false and dashboard_summary never
        // gets incremented for newly created POI.
        $poi = Poi::create($data + ['created_by' => $user->id, 'status' => 'aktif']);

        return redirect()->route('poi.show', $poi)->with('status', 'POI berhasil ditambahkan.');
    }

    public function show(Request $request, Poi $poi): View
    {
        $this->ensureReadAccess($request->user(), $poi->kantor_id);

        $poi->load([
            'kantor',
            'createdBy',
            'reopenLogs' => fn ($q) => $q->with('user')->latest('created_at'),
        ]);

        return view('poi.show', [
            'poi' => $poi,
            'canManage' => $this->canManage($request->user(), $poi->kantor_id),
        ]);
    }

    public function edit(Request $request, Poi $poi): View
    {
        $user = $request->user();
        $this->ensureKantorAccess($user, $poi->kantor_id);

        $poi->load(['reopenLogs' => fn ($q) => $q->with('user')->latest('created_at')]);

        return view('poi.edit', [
            'poi' => $poi,
            'kantorOptions' => $this->kantorOptionsFor($user),
            'sektorOptions' => Poi::SEKTOR_OPTIONS,
            'areaOptions' => Poi::AREA_OPTIONS,
            'statusMitraOptions' => Poi::STATUS_MITRA_OPTIONS,
            'kunjunganCount' => $poi->kunjungan()->count(),
        ]);
    }

    public function update(Request $request, Poi $poi): RedirectResponse
    {
        $user = $request->user();
        $this->ensureKantorAccess($user, $poi->kantor_id);

        $data = $this->validatePoi($request);

        // Kantor reassignment must also land on a kantor this user owns.
        $this->ensureKantorAccess($user, (int) $data['kantor_id']);

        $poi->update($data);

        return redirect()->route('poi.edit', $poi)->with('status', 'POI berhasil diperbarui.');
    }

    /**
     * "Delete" is a soft toggle (status -> nonaktif) with a mandatory reason,
     * logged to poi_reopen_log atomically alongside the status change.
     */
    public function destroy(Request $request, Poi $poi): RedirectResponse
    {
        $user = $request->user();
        $this->ensureKantorAccess($user, $poi->kantor_id);

        $data = $request->validate([
            'alasan' => ['required', 'string', 'max:1000'],
        ]);

        if ($poi->status === 'nonaktif') {
            return back()->with('status', 'POI sudah dalam status nonaktif.');
        }

        DB::transaction(function () use ($poi, $data, $user) {
            $poi->update(['status' => 'nonaktif']);

            PoiReopenLog::create([
                'poi_id' => $poi->id,
                'action' => 'hapus',
                'alasan' => $data['alasan'],
                'user_id' => $user->id,
            ]);
        });

        return redirect()->route('poi.index')->with('status', 'POI berhasil dinonaktifkan.');
    }

    /**
     * Hard delete (added 2026-07-23 at product request — nonaktif rows were
     * accumulating as junk with no way to actually remove them). Unlike
     * destroy() above this is a real DB delete: kunjungan (+produk),
     * poi_reopen_log, and geocode_failed rows all cascade away with it, so
     * the route is role:admin only (see routes/poi.php). No "must be
     * nonaktif first" gate on purpose — the whole point is a direct delete,
     * and the confirm dialog in the edit view carries the warning instead.
     * Eloquent delete() (not a query-builder delete) so PoiObserver::deleted()
     * keeps dashboard_summary in sync for still-aktif rows.
     */
    public function destroyPermanent(Request $request, Poi $poi): RedirectResponse
    {
        $this->ensureKantorAccess($request->user(), $poi->kantor_id);

        $nama = $poi->nama_poi;
        $kunjunganCount = $poi->kunjungan()->count();

        $poi->delete();

        $suffix = $kunjunganCount > 0 ? " {$kunjunganCount} kunjungan terkait ikut terhapus." : '';

        return redirect()->route('poi.index')->with('status', "POI {$nama} berhasil dihapus permanen.{$suffix}");
    }

    public function reopen(Request $request, Poi $poi): RedirectResponse
    {
        $user = $request->user();
        $this->ensureKantorAccess($user, $poi->kantor_id);

        $data = $request->validate([
            'alasan' => ['required', 'string', 'max:1000'],
        ]);

        if ($poi->status === 'aktif') {
            return back()->with('status', 'POI sudah dalam status aktif.');
        }

        DB::transaction(function () use ($poi, $data, $user) {
            $poi->update(['status' => 'aktif']);

            PoiReopenLog::create([
                'poi_id' => $poi->id,
                'action' => 'reopen',
                'alasan' => $data['alasan'],
                'user_id' => $user->id,
            ]);
        });

        return redirect()->route('poi.edit', $poi)->with('status', 'POI berhasil diaktifkan kembali.');
    }

    /**
     * Applies the server-side kantor scope for the index listing and returns
     * the kantor/area/cluster options + selections the current user is
     * allowed to filter by. This is the hard security boundary from the
     * brief (old system's IDOR bug) — it must not be derived from anything
     * the client sends unchecked.
     *
     * Area/Cabang-Cluster (2026-07-23, via NarrowsKantorByAreaCluster) narrow
     * the SAME role-scoped pool a specific `?kantor=` pick already draws
     * from, before that pick is validated — so a forged `kantor` outside the
     * currently-narrowed Area/Cluster is rejected exactly like a forged
     * kantor outside admin_final's ownership always was.
     *
     * @return array{kantorOptions: Collection<int, Kantor>, areaOptions: Collection<int, string>, selectedArea: ?string, clusterOptions: Collection<int, string>, selectedCluster: ?string}
     */
    private function scopeIndexQuery($query, User $user, Request $request): array
    {
        if ($user->isSales()) {
            // hard-scoped to the single session-locked active kantor, the
            // `kantor`/`area`/`cluster` query params are ignored entirely
            // (never trusted) — no picker renders for sales anyway.
            $query->where('kantor_id', (int) session('active_kantor_id'));

            return [
                'kantorOptions' => new Collection(),
                'areaOptions' => new Collection(),
                'selectedArea' => null,
                'clusterOptions' => new Collection(),
                'selectedCluster' => null,
            ];
        }

        $allowedKantor = $user->isAdmin()
            ? Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->orderBy('nama')->get()
            : $user->kantor()->orderBy('nama')->get();

        $narrowed = $this->narrowKantorByAreaCluster($request, $allowedKantor);
        $narrowedIds = $narrowed['kantorOptions']->pluck('id')->all();

        if ($request->filled('kantor') && in_array((int) $request->input('kantor'), $narrowedIds, true)) {
            $query->where('kantor_id', (int) $request->input('kantor'));
        } elseif ($user->isAdmin()) {
            // Admin's true default (nothing picked at any level) stays
            // unconstrained — only add a whereIn once Area/Cluster actually
            // narrowed the pool below "every kantor".
            if ($narrowed['selectedArea'] !== null || $narrowed['selectedCluster'] !== null) {
                $query->whereIn('kantor_id', $narrowedIds);
            }
        } else {
            // admin_final: always constrained to at least their owned set.
            $query->whereIn('kantor_id', $narrowedIds);
        }

        return $narrowed;
    }

    private function kantorOptionsFor(User $user): Collection
    {
        if ($user->isAdmin()) {
            return Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->orderBy('nama')->get();
        }

        return $user->kantor()->orderBy('nama')->get();
    }

    /**
     * Write-path check: sales never reach here (blocked by role middleware
     * on the route itself), admin unrestricted, admin_final must own the
     * kantor in question.
     */
    private function ensureKantorAccess(User $user, int $kantorId): void
    {
        if ($user->isAdmin()) {
            return;
        }

        abort_unless($user->hasKantor($kantorId), 403, 'Anda tidak punya akses ke kantor ini.');
    }

    private function ensureReadAccess(User $user, int $kantorId): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if ($user->isAdminFinal()) {
            abort_unless($user->hasKantor($kantorId), 403, 'Anda tidak punya akses ke kantor ini.');

            return;
        }

        abort_unless((int) session('active_kantor_id') === $kantorId, 403, 'Anda tidak punya akses ke POI ini.');
    }

    private function canManage(User $user, int $kantorId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isAdminFinal() && $user->hasKantor($kantorId);
    }

    /**
     * sektor/area accept any free text (2026-07-15 fix) — they're plain
     * VARCHAR columns now (see the ENUM->free-text migration), and
     * PoiImport deliberately lets bulk-imported data hold values outside
     * Poi::SEKTOR_OPTIONS/AREA_OPTIONS. Locking this form to Rule::in()
     * meant an admin editing ANY field on an already-imported POI whose
     * sektor/area wasn't one of the curated values would fail validation or
     * have it silently reassigned — the curated lists are still offered in
     * the form as convenient suggestions (poi/_form.blade.php's <input
     * list="...">), just no longer enforced. status_mitra stays a strict
     * Rule::in() — unlike sektor/area it's a real DB ENUM hard-compared all
     * over the app (Dashboard's BNI/Non split, sales' prospectable-POI
     * query), so it can't become free text without breaking that math.
     */
    private function validatePoi(Request $request): array
    {
        return $request->validate([
            'nama_poi' => ['required', 'string', 'max:255'],
            'alamat' => ['required', 'string'],
            'sektor' => ['required', 'string', 'max:255'],
            'sub_sektor' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'kantor_id' => ['required', 'integer', Rule::exists('kantor', 'id')],
            'status_mitra' => ['required', Rule::in(Poi::STATUS_MITRA_OPTIONS)],
            'pic' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
