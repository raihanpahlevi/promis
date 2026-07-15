<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PilihKantorController extends Controller
{
    public function edit(Request $request): View
    {
        $kantorOptions = $request->user()->kantor()->orderBy('nama')->get();

        return view('auth.pilih-kantor', compact('kantorOptions'));
    }

    /**
     * Re-validates the chosen kantor against user_kantor server-side — this is the
     * exact check that was missing in the old pilih_kantor.php (PRD §2/§7).
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kantor_id' => ['required', 'integer'],
        ]);

        if (! $request->user()->hasKantor((int) $data['kantor_id'])) {
            throw ValidationException::withMessages([
                'kantor_id' => 'Kantor tidak valid untuk akun Anda.',
            ]);
        }

        session(['active_kantor_id' => (int) $data['kantor_id']]);

        return redirect()->intended(route('dashboard'));
    }
}
