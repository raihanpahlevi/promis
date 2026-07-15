<?php

namespace Database\Seeders;

use App\Models\Kantor;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Two things must exist before anything else works: the sentinel "ALL"
     * kantor row (dashboard_summary's global aggregate points to it — see
     * DashboardSummaryService) and one bootstrap admin (only admin can create
     * other users, so someone has to exist first).
     */
    public function run(): void
    {
        Kantor::firstOrCreate(
            ['kode' => Kantor::SENTINEL_ALL_KODE],
            ['nama' => 'Seluruh Kantor', 'is_active' => false],
        );

        $bootstrapNpp = (string) env('ADMIN_BOOTSTRAP_NPP', 'admin');

        User::firstOrCreate(
            ['npp' => $bootstrapNpp],
            [
                'nama_lengkap' => env('ADMIN_BOOTSTRAP_NAME', 'Administrator'),
                'password' => $bootstrapNpp, // hashed via the User model's 'hashed' cast
                'role' => User::ROLE_ADMIN,
                'force_password_change' => true,
                'is_active' => true,
            ],
        );
    }
}
