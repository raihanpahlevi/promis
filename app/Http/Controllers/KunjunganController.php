<?php

namespace App\Http\Controllers;

use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\KunjunganProduk;
use App\Models\Poi;
use App\Models\User;
use App\Services\DashboardSummaryService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KunjunganController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * Visit-logging form — sales and admin_final only (confirmed against the real v1
     * kunjungan.php; `admin` doesn't log field visits). `sales` is scoped to their
     * session-locked active kantor. `admin_final` must explicitly pick ONE of their own
     * kantor first (never 'ALL' — a kunjungan always belongs to exactly one kantor); until
     * they do, the sektor/area/POI picker sections just don't render.
     *
     * The POI picker offers every POI that (a) is aktif and (b) hasn't partnered with BNI
     * yet (status_mitra = Bukan Nasabah BNI — a kunjungan is a prospecting visit, not
     * something you log against an existing partner). Product decision (2026-07-15): a POI
     * currently locked to a *different* sales via collecting_by ("gathering documents") is
     * no longer hidden from the list — it still shows up, but is flagged with who's
     * collecting it (collectingBy relation, eager-loaded) so the picker can block the rest
     * of the form and explain why, instead of the POI just silently not being there. The
     * actual submission is still hard-rejected server-side in store() either way — this is
     * about the sales seeing the reason up front, not a new security boundary. Sektor/sub
     * sektor/area are cascading GET filters scoped to that same (now unfiltered-by-lock)
     * pool.
     */
    public function create(Request $request): View
    {
        $user = $request->user();

        $kantorOptions = collect();
        $kantorId = null;

        if ($user->isAdminFinal()) {
            $kantorOptions = $user->kantor()->orderBy('nama')->get();
            $requested = $request->filled('kantor') ? (int) $request->input('kantor') : null;
            if ($requested !== null && $kantorOptions->contains('id', $requested)) {
                $kantorId = $requested;
            }
        } elseif ($user->isAdmin()) {
            // admin isn't tied to specific kantor via user_kantor, so — unlike
            // admin_final — the picker offers every real kantor, not an owned subset.
            $kantorOptions = Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->orderBy('nama')->get();
            $requested = $request->filled('kantor') ? (int) $request->input('kantor') : null;
            if ($requested !== null && $kantorOptions->contains('id', $requested)) {
                $kantorId = $requested;
            }
        } else {
            $kantorId = (int) session('active_kantor_id');
        }

        $sektorOptions = $subSektorOptions = $areaOptions = $poiOptions = collect();
        $sektor = $request->input('sektor');
        $subSektor = $request->input('sub_sektor');
        $area = $request->input('area');

        if ($kantorId !== null) {
            $scoped = fn () => $this->prospectablePoiQuery($kantorId);

            $sektorOptions = $scoped()->select('sektor')->distinct()->orderBy('sektor')->pluck('sektor');
            $areaOptions = $scoped()->whereNotNull('area')->select('area')->distinct()->orderBy('area')->pluck('area');

            if ($sektor) {
                $subSektorOptions = $scoped()->where('sektor', $sektor)
                    ->whereNotNull('sub_sektor')->where('sub_sektor', '!=', '')
                    ->select('sub_sektor')->distinct()->orderBy('sub_sektor')->pluck('sub_sektor');
            }

            $poiQuery = $scoped();
            if ($sektor) {
                $poiQuery->where('sektor', $sektor);
            }
            if ($subSektor) {
                $poiQuery->where('sub_sektor', $subSektor);
            }
            if ($area) {
                $poiQuery->where('area', $area);
            }

            // collectingBy eager-loaded so the picker can show "sedang di-collecting
            // oleh NPP - Nama" for a POI locked to someone else, instead of the POI
            // just being silently absent from the list.
            $poiOptions = $poiQuery->with('collectingBy:id,npp,nama_lengkap')
                ->orderBy('nama_poi')
                ->get(['id', 'nama_poi', 'collecting_by']);
        }

        return view('kunjungan.create', [
            'kantorOptions' => $kantorOptions,
            'kantorId' => $kantorId,
            'poiOptions' => $poiOptions,
            'sektorOptions' => $sektorOptions,
            'subSektorOptions' => $subSektorOptions,
            'areaOptions' => $areaOptions,
            'hasilOptions' => Kunjungan::HASIL_OPTIONS,
            'produkOptions' => Kunjungan::PRODUK_OPTIONS,
            'statusMitraAfterClosingOptions' => Poi::STATUS_MITRA_AFTER_CLOSING,
            'filters' => $request->only(['sektor', 'sub_sektor', 'area']),
        ]);
    }

    /**
     * Re-derives (never trusts) the acting kantor server-side exactly like create():
     * sales -> session active kantor; admin_final -> a kantor_id in the payload,
     * validated against their own assignment. poi_id is validated against that kantor
     * plus the same aktif/not-yet-BNI pool the picker used — a forged poi_id for another
     * kantor, a nonaktif POI, or one already partnered with BNI is rejected here even if
     * the client tampers with the form. sales_id is always the authenticated user.
     *
     * hasil='Collecting Dokumen' locks the POI to this user (poi.collecting_by); any other
     * hasil clears that lock. A POI currently locked to a *different* sales is rejected
     * outright — mirrors the real v1 "sedang collecting oleh sales lain" guard.
     *
     * hasil='Closing' requires status_mitra_baru (Non Merchant or Merchant BNI, sales
     * picks which — there's no v1-era single 'BNI' bucket to fall back on) and applies it
     * to the POI. A closing also stamps poi.pic with the closing sales' "Nama (Unit)"
     * (User::picLabel) — always overwriting whatever was there before (stale import data,
     * or a previous closer from another unit), since PIC should reflect whoever most
     * recently actually closed the POI, not who happened to be listed first.
     *
     * norek_cif (2026-07-22) is a free-text field on the form (not gated to Closing like
     * pic/status_mitra — a sales can capture it on any hasil) that's recorded on the
     * Kunjungan row AND stamped onto poi.norek_cif so it shows on the POI's detail page
     * even if the POI never had one before. A blank norek_cif on this visit leaves the
     * POI's existing value untouched rather than clearing it.
     *
     * Both the Kunjungan row and the Poi update happen in one transaction so PoiObserver's
     * dashboard_summary bookkeeping never sees a half-applied closing.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->isAdminFinal()) {
            $kantorData = $request->validate(['kantor_id' => ['required', 'integer']]);
            abort_unless($user->hasKantor((int) $kantorData['kantor_id']), 403, 'Kantor tidak valid untuk akun Anda.');
            $kantorId = (int) $kantorData['kantor_id'];
        } elseif ($user->isAdmin()) {
            $kantorData = $request->validate(['kantor_id' => ['required', 'integer', Rule::exists('kantor', 'id')]]);
            $kantorId = (int) $kantorData['kantor_id'];
        } else {
            $kantorId = (int) session('active_kantor_id');
        }

        $data = $request->validate([
            'poi_id' => [
                'required',
                'integer',
                Rule::exists('poi', 'id')
                    ->where('kantor_id', $kantorId)
                    ->where('status', 'aktif')
                    ->where('status_mitra', Poi::BELUM_BERMITRA_BNI),
            ],
            'produk' => ['nullable', 'array'],
            'produk.*' => [Rule::in(Kunjungan::PRODUK_OPTIONS)],
            'hasil' => ['required', 'string', Rule::in(Kunjungan::HASIL_OPTIONS)],
            'status_mitra_baru' => [
                Rule::requiredIf(fn () => $request->input('hasil') === Kunjungan::HASIL_CLOSING),
                'nullable',
                Rule::in(Poi::STATUS_MITRA_AFTER_CLOSING),
            ],
            'norek_cif' => ['nullable', 'string', 'max:100'],
            'nominal' => ['nullable', 'numeric', 'min:0'],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ], [
            'poi_id.exists' => 'POI yang dipilih tidak valid: sudah bermitra BNI, nonaktif, atau bukan dari kantor ini.',
            'status_mitra_baru.required_if' => 'Pilih status mitra baru saat hasil kunjungan Closing.',
        ]);

        $poi = Poi::findOrFail($data['poi_id']);

        // The collecting_by check + the Kunjungan/Poi writes below all happen
        // inside one transaction against a locked row (2026-07-15 fix) — the
        // check used to run before the transaction even opened, so two
        // concurrent submissions for the same POI could both read
        // collecting_by === null and both pass, creating two Kunjungan rows
        // and leaving collecting_by pointing at whichever commit landed last
        // instead of the second one being rejected as the docblock above
        // claims. Locking here makes the second request wait, then see the
        // first request's already-applied lock and correctly get rejected.
        DB::transaction(function () use ($poi, $user, $data) {
            $lockedPoi = Poi::whereKey($poi->id)->lockForUpdate()->firstOrFail();

            if ($lockedPoi->collecting_by !== null && $lockedPoi->collecting_by !== $user->id) {
                throw ValidationException::withMessages([
                    'poi_id' => 'POI ini sedang dalam proses collecting dokumen oleh sales lain.',
                ]);
            }

            $kunjungan = Kunjungan::create([
                'poi_id' => $lockedPoi->id,
                'sales_id' => $user->id,
                // Always "today", never a client-submitted date — matches the real v1
                // form, which has no date field at all and hardcodes date('Y-m-d').
                'tanggal_kunjungan' => now()->toDateString(),
                'hasil' => $data['hasil'],
                'norek_cif' => $data['norek_cif'] ?? null,
                'nominal' => $data['nominal'] ?? null,
                'catatan' => $data['catatan'] ?? null,
            ]);

            foreach (array_unique($data['produk'] ?? []) as $produk) {
                KunjunganProduk::create(['kunjungan_id' => $kunjungan->id, 'produk' => $produk]);
            }

            $isClosing = $data['hasil'] === Kunjungan::HASIL_CLOSING;

            $lockedPoi->update([
                'collecting_by' => $data['hasil'] === Kunjungan::HASIL_COLLECTING_DOKUMEN ? $user->id : null,
                'status_mitra' => $isClosing ? $data['status_mitra_baru'] : $lockedPoi->status_mitra,
                'pic' => $isClosing ? $user->picLabel() : $lockedPoi->pic,
                // Stamped whenever a sales fills this in, regardless of hasil
                // (unlike pic/status_mitra, which are Closing-only) — a blank
                // field on THIS visit leaves the POI's existing value alone
                // rather than clearing out a norek/CIF recorded on an earlier
                // visit (ConvertEmptyStringsToNull already turns an empty
                // form field into null before validation, so this is a plain
                // null check, not also an empty-string one).
                'norek_cif' => $data['norek_cif'] ?? $lockedPoi->norek_cif,
            ]);
        });

        // admin_final can't reach kunjungan.riwayat (sales-only route) — send them to
        // the kantor-wide riwayat instead, pre-filtered to the kantor they just used.
        return $user->isSales()
            ? redirect()->route('kunjungan.riwayat')->with('status', 'Kunjungan berhasil dicatat.')
            : redirect()->route('kunjungan.index', ['kantor_id' => $poi->kantor_id])->with('status', 'Kunjungan berhasil dicatat.');
    }

    /**
     * Undoes a mistaken Closing/Collecting Dokumen entry — admin/admin_final
     * only, from the kantor-wide riwayat (kunjungan.index). Closing being
     * reopened puts the POI back to Poi::BELUM_BERMITRA_BNI (the only sane
     * "undo" target — this app doesn't track what status_mitra was before
     * the closing); Collecting Dokumen being reopened just clears the
     * collecting_by lock. The kunjungan row itself is deleted (product
     * decision: a mistaken entry shouldn't linger in history at all), and
     * DashboardSummaryService::reverseKunjungan() manually undoes the
     * total_kunjungan/total_closing counters KunjunganObserver::created()
     * applied — there's no deleted() observer to do that automatically.
     *
     * Only reopenable while it's still the MOST RECENT kunjungan for that
     * POI: a Closing already blocks any further kunjungan against that POI
     * (prospectablePoiQuery excludes anything but Poi::BELUM_BERMITRA_BNI),
     * so this is nearly always true for Closing; for Collecting Dokumen it's
     * a real guard — a newer kunjungan may have already changed or cleared
     * collecting_by, and reopening the older one out of order would corrupt
     * that newer state.
     *
     * The "still latest" check and the delete both happen inside one
     * transaction with the POI + kunjungan rows locked (2026-07-15 fix) — a
     * double-submit (double-click, two tabs) used to be able to pass the
     * check in both requests before either committed, double-deleting
     * counters via reverseKunjungan(). The second request now blocks on the
     * lock until the first commits, then correctly sees its own "not latest
     * anymore" (or "already gone") state and fails instead of double-firing.
     */
    public function reopen(Request $request, Kunjungan $kunjungan): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            in_array($kunjungan->hasil, [Kunjungan::HASIL_CLOSING, Kunjungan::HASIL_COLLECTING_DOKUMEN], true),
            404
        );

        $poi = $kunjungan->poi;

        if ($user->isAdminFinal()) {
            abort_unless($user->hasKantor($poi->kantor_id), 403, 'Kantor tidak valid untuk akun Anda.');
        }

        $reopened = DB::transaction(function () use ($kunjungan, $poi) {
            Poi::whereKey($poi->id)->lockForUpdate()->value('id');

            $latestKunjungan = Kunjungan::where('poi_id', $poi->id)->orderByDesc('id')->lockForUpdate()->first();

            if ($latestKunjungan === null || $kunjungan->id !== $latestKunjungan->id) {
                return false;
            }

            $isClosing = $kunjungan->hasil === Kunjungan::HASIL_CLOSING;

            $poi->update($isClosing
                ? ['status_mitra' => Poi::BELUM_BERMITRA_BNI]
                : ['collecting_by' => null]);

            app(DashboardSummaryService::class)->reverseKunjungan($poi->kantor_id, $isClosing, $kunjungan->tanggal_kunjungan);

            $kunjungan->produkList()->delete();
            $kunjungan->delete();

            return true;
        });

        if (! $reopened) {
            return back()->withErrors('Kunjungan ini bukan yang terbaru untuk POI tersebut — sudah ada kunjungan lain sesudahnya, tidak bisa direopen.');
        }

        return back()->with('status', 'Kunjungan berhasil direopen — POI dikembalikan ke status semula.');
    }

    /**
     * No longer excludes POI locked to another sales (see create()'s docblock)
     * — the picker shows them and explains the lock instead. store() is what
     * actually enforces the lock at submission time.
     */
    private function prospectablePoiQuery(int $kantorId): Builder
    {
        return Poi::query()
            ->where('kantor_id', $kantorId)
            ->where('status', 'aktif')
            ->where('status_mitra', Poi::BELUM_BERMITRA_BNI);
    }

    /**
     * Personal riwayat for `sales` — their own kunjungan only, server-side filtered
     * and paginated (this table grows unbounded, never `get()` + loop it).
     */
    public function riwayat(Request $request): View
    {
        $filters = $request->validate([
            'hasil' => ['nullable', 'string', Rule::in(Kunjungan::HASIL_OPTIONS)],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date'],
            'poi' => ['nullable', 'string', 'max:255'],
        ]);

        $kunjungans = Kunjungan::query()
            ->with(['poi', 'produkList'])
            ->where('sales_id', Auth::id())
            ->when($filters['hasil'] ?? null, fn ($q, $hasil) => $q->where('hasil', $hasil))
            ->when($filters['dari'] ?? null, fn ($q, $dari) => $q->whereDate('tanggal_kunjungan', '>=', $dari))
            ->when($filters['sampai'] ?? null, fn ($q, $sampai) => $q->whereDate('tanggal_kunjungan', '<=', $sampai))
            ->when($filters['poi'] ?? null, fn ($q, $poi) => $q->whereHas('poi', fn ($pq) => $pq->where('nama_poi', 'like', "%{$poi}%")))
            ->orderByDesc('tanggal_kunjungan')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('kunjungan.riwayat', [
            'kunjungans' => $kunjungans,
            'hasilOptions' => Kunjungan::HASIL_OPTIONS,
            'filters' => $filters,
        ]);
    }

    /**
     * Kantor-wide riwayat for admin/admin_final. admin sees everything; admin_final is
     * scoped server-side (via query, not UI hiding) to only the kantor they're assigned
     * to in user_kantor. Also server-side paginated.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $filters = $request->validate([
            'hasil' => ['nullable', 'string', Rule::in(Kunjungan::HASIL_OPTIONS)],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date'],
            'poi' => ['nullable', 'string', 'max:255'],
            'kantor_id' => ['nullable', 'integer'],
            'sales_id' => ['nullable', 'integer'],
        ]);

        $allowedKantorIds = $user->isAdminFinal()
            ? $user->kantor()->pluck('kantor.id')
            : null; // null = admin, unrestricted

        // admin_final requesting a kantor_id outside their own assignment must not leak
        // any rows — intersect rather than trust the filter value outright.
        $kantorFilterId = $filters['kantor_id'] ?? null;
        if ($allowedKantorIds !== null && $kantorFilterId && ! $allowedKantorIds->contains((int) $kantorFilterId)) {
            $kantorFilterId = -1; // guaranteed no match
        }

        $kunjungans = Kunjungan::query()
            ->with(['poi.kantor', 'sales', 'produkList'])
            ->whereHas('poi', function ($q) use ($allowedKantorIds, $kantorFilterId, $filters) {
                if ($allowedKantorIds !== null) {
                    $q->whereIn('kantor_id', $allowedKantorIds);
                }
                if ($kantorFilterId) {
                    $q->where('kantor_id', $kantorFilterId);
                }
                if (! empty($filters['poi'])) {
                    $q->where('nama_poi', 'like', '%'.$filters['poi'].'%');
                }
            })
            ->when($filters['hasil'] ?? null, fn ($q, $hasil) => $q->where('hasil', $hasil))
            ->when($filters['dari'] ?? null, fn ($q, $dari) => $q->whereDate('tanggal_kunjungan', '>=', $dari))
            ->when($filters['sampai'] ?? null, fn ($q, $sampai) => $q->whereDate('tanggal_kunjungan', '<=', $sampai))
            ->when($filters['sales_id'] ?? null, fn ($q, $salesId) => $q->where('sales_id', $salesId))
            ->orderByDesc('tanggal_kunjungan')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $kantorOptions = $allowedKantorIds !== null
            ? Kantor::query()->whereIn('id', $allowedKantorIds)->orderBy('nama')->get(['id', 'nama'])
            : Kantor::query()->orderBy('nama')->get(['id', 'nama']);

        $salesOptions = User::query()
            ->where('role', User::ROLE_SALES)
            ->when($allowedKantorIds !== null, fn ($q) => $q->whereHas('kantor', fn ($kq) => $kq->whereIn('kantor.id', $allowedKantorIds)))
            ->orderBy('nama_lengkap')
            ->get(['id', 'nama_lengkap']);

        // Reopen is only offered on a row that's still the latest kunjungan
        // for its POI (see reopen()) — computed once here for the whole page
        // rather than per-row, to avoid an N+1 query per table row.
        $latestKunjunganIdByPoi = Kunjungan::whereIn('poi_id', $kunjungans->pluck('poi_id'))
            ->selectRaw('poi_id, MAX(id) as latest_id')
            ->groupBy('poi_id')
            ->pluck('latest_id', 'poi_id');

        return view('kunjungan.index', [
            'kunjungans' => $kunjungans,
            'hasilOptions' => Kunjungan::HASIL_OPTIONS,
            'kantorOptions' => $kantorOptions,
            'salesOptions' => $salesOptions,
            'filters' => $filters,
            'latestKunjunganIdByPoi' => $latestKunjunganIdByPoi,
        ]);
    }
}
