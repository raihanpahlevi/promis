<?php

namespace App\Http\Controllers;

use App\Models\Kantor;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Manajemen User" (PRD-mandated admin-only user CRUD). Unlike the POI module,
 * there is no admin_final carve-out here — every route in routes/user.php is
 * gated `role:admin` at the route level, this controller does not re-check
 * roles itself.
 *
 * Users are never hard-deleted (PRD: "nonaktifkan user", not "hapus") — see
 * toggleActive(). Password-reset-to-NPP is the only recovery path since this
 * system has no self-service "forgot password" flow.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $query = User::query()->with(['kantor', 'unit']);

        if ($request->filled('q')) {
            $search = $request->input('q');
            $query->where(function ($q) use ($search) {
                $q->where('npp', 'like', "%{$search}%")
                    ->orWhere('nama_lengkap', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->filled('kantor')) {
            $kantorId = (int) $request->input('kantor');
            $query->whereHas('kantor', fn ($q) => $q->where('kantor.id', $kantorId));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->input('is_active') === '1');
        }

        $users = $query->orderBy('nama_lengkap')->paginate(15)->withQueryString();

        return view('users.index', [
            'users' => $users,
            'kantorOptions' => $this->kantorOptions(),
            'roleOptions' => $this->roleOptions(),
            'filters' => $request->only(['q', 'role', 'kantor', 'is_active']),
        ]);
    }

    public function create(): View
    {
        return view('users.create', [
            'kantorOptions' => $this->kantorOptions(),
            'roleOptions' => $this->roleOptions(),
            'unitOptions' => $this->unitOptions(),
        ]);
    }

    /**
     * Password awal = NPP itself and force_password_change is always true on
     * creation — this is the mandated "password awal = NPP" scheme (same as
     * DatabaseSeeder's bootstrap admin) and is only safe because
     * ForcePasswordChange middleware cannot be bypassed. Do not weaken either
     * of those two here.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateUser($request, includeNpp: true);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'npp' => $data['npp'],
                'nama_lengkap' => $data['nama_lengkap'],
                'unit_id' => $this->resolveUnitId($data['unit_name'] ?? null),
                'password' => $data['npp'], // hashed via User's 'hashed' cast
                'role' => $data['role'],
                'force_password_change' => true,
                'is_active' => true,
            ]);

            $user->kantor()->sync($data['kantor_ids'] ?? []);

            return $user;
        });

        return redirect()->route('user.index')
            ->with('status', "User {$user->nama_lengkap} berhasil ditambahkan. Password awal = NPP ({$user->npp}).");
    }

    public function edit(User $user): View
    {
        $user->load('kantor');

        return view('users.edit', [
            'user' => $user,
            'kantorOptions' => $this->kantorOptions(),
            'roleOptions' => $this->roleOptions(),
            'unitOptions' => $this->unitOptions(),
        ]);
    }

    /**
     * NPP is deliberately not editable here — it is the permanent login
     * username (PRD: "NPP ini langsung jadi Username login") and the request
     * payload's npp (if any) is ignored entirely, never trusted.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validateUser($request, includeNpp: false);

        // Self-lockout guard: an admin editing their own account can't demote
        // themselves away from `admin` via this form. Not explicitly required
        // by the PRD, but accidentally locking out the only admin account is a
        // real operational risk this system has no other recovery path for.
        if ($user->id === $request->user()->id
            && $user->role === User::ROLE_ADMIN
            && $data['role'] !== User::ROLE_ADMIN) {
            return back()->withErrors([
                'role' => 'Anda tidak dapat mengubah role akun Anda sendiri dari admin.',
            ])->withInput();
        }

        DB::transaction(function () use ($user, $data) {
            $user->update([
                'nama_lengkap' => $data['nama_lengkap'],
                'unit_id' => $this->resolveUnitId($data['unit_name'] ?? null),
                'role' => $data['role'],
            ]);

            $user->kantor()->sync($data['kantor_ids'] ?? []);
        });

        return redirect()->route('user.edit', $user)->with('status', 'User berhasil diperbarui.');
    }

    /**
     * Toggles is_active. Deactivation (not deletion) is the PRD-mandated way
     * to remove a user's access — LoginController already refuses login for
     * is_active=false, this just flips the flag.
     *
     * Self-lockout guard: an admin cannot deactivate their own account via
     * this UI (see note on update() above for the same reasoning).
     */
    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id && $user->is_active) {
            return back()->withErrors([
                'is_active' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.',
            ]);
        }

        $user->update(['is_active' => ! $user->is_active]);

        $status = $user->is_active ? 'diaktifkan kembali' : 'dinonaktifkan';

        return back()->with('status', "User {$user->nama_lengkap} berhasil {$status}.");
    }

    /**
     * Manual "lupa password" recovery: resets password back to the user's own
     * NPP and re-flags force_password_change, same mechanism as creation.
     * There is no self-service forgot-password flow in this system — an
     * admin always does this by hand.
     */
    public function resetPassword(User $user): RedirectResponse
    {
        $user->forceFill([
            'password' => $user->npp, // hashed via User's 'hashed' cast
            'force_password_change' => true,
        ])->save();

        return back()->with('status', "Password {$user->nama_lengkap} berhasil direset ke NPP ({$user->npp}).");
    }

    private function kantorOptions()
    {
        return Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->orderBy('nama')->get();
    }

    /**
     * Only active units are offered — deactivating a unit (UnitController)
     * hides it from this picker without breaking existing users still
     * assigned to it (unit_id is nullOnDelete, not restricted).
     */
    private function unitOptions()
    {
        return Unit::where('is_active', true)->orderBy('nama')->get();
    }

    /**
     * The Unit / Jabatan field is a free-text input with a suggestion list
     * (not a closed dropdown): typing a name that matches an existing unit
     * (case/whitespace-insensitive) reuses it, typing a new name auto-creates
     * it — same "resolve or create" behavior as UserImport::resolveOrCreateUnit(),
     * so manual entry and bulk import stay consistent.
     */
    private function resolveUnitId(?string $rawName): ?int
    {
        $rawName = trim((string) $rawName);

        if ($rawName === '') {
            return null;
        }

        $normalized = mb_strtoupper(preg_replace('/\s+/', ' ', $rawName));

        $unit = Unit::whereRaw('UPPER(TRIM(nama)) = ?', [$normalized])->first();

        return $unit?->id ?? Unit::create(['nama' => $rawName, 'is_active' => true])->id;
    }

    /**
     * @return string[]
     */
    private function roleOptions(): array
    {
        return [User::ROLE_ADMIN, User::ROLE_ADMIN_FINAL, User::ROLE_SALES];
    }

    /**
     * Shared validation for create/update. `kantor_ids` is required for
     * admin_final/sales (their access is scoped by kantor assignment) but
     * left optional for `admin`: an admin has unrestricted cross-kantor
     * access regardless of any kantor row in user_kantor (see
     * PoiController::kantorOptionsFor()/ensureKantorAccess() — admin never
     * consults hasKantor() at all), so forcing a kantor pick on admin
     * creation would be busywork with no security meaning.
     */
    private function validateUser(Request $request, bool $includeNpp): array
    {
        $rules = [
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'unit_name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in($this->roleOptions())],
            // Rule::requiredIf (not 'nullable' + a closure) on purpose: a
            // plain closure rule is silently skipped by Laravel's validator
            // whenever the field is absent from the request (which is exactly
            // what happens here — unchecked checkboxes just don't appear in
            // the payload at all), so a hand-rolled "required unless admin"
            // closure would never fire for the empty-selection case.
            // Rule::requiredIf is treated as an implicit rule, so it still
            // runs even when kantor_ids is completely missing.
            'kantor_ids' => [
                Rule::requiredIf(fn () => $request->input('role') !== User::ROLE_ADMIN),
                'array',
            ],
            'kantor_ids.*' => [
                'integer',
                Rule::exists('kantor', 'id')->where(fn ($q) => $q->where('kode', '!=', Kantor::SENTINEL_ALL_KODE)),
            ],
        ];

        if ($includeNpp) {
            $rules['npp'] = ['required', 'string', 'max:50', 'unique:users,npp'];
        }

        return $request->validate($rules);
    }
}
