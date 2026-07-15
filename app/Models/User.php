<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['npp', 'nama_lengkap', 'password', 'role', 'unit_id', 'force_password_change', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_ADMIN_FINAL = 'admin_final';

    public const ROLE_SALES = 'sales';

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'force_password_change' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function kantor(): BelongsToMany
    {
        return $this->belongsToMany(Kantor::class, 'user_kantor');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function kunjungan(): HasMany
    {
        return $this->hasMany(Kunjungan::class, 'sales_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isAdminFinal(): bool
    {
        return $this->role === self::ROLE_ADMIN_FINAL;
    }

    public function isSales(): bool
    {
        return $this->role === self::ROLE_SALES;
    }

    /**
     * Whether this user is assigned to the given kantor (server-side kantor-check,
     * closes the pilih_kantor.php / edit_poi.php IDOR gap from the old system).
     */
    public function hasKantor(int $kantorId): bool
    {
        return $this->kantor()->where('kantor.id', $kantorId)->exists();
    }

    /**
     * "Nama (Unit)" used to stamp poi.pic when this sales closes a POI —
     * unit_id is nullable, so a user with none just yields the bare name.
     */
    public function picLabel(): string
    {
        return $this->unit ? "{$this->nama_lengkap} ({$this->unit->nama})" : $this->nama_lengkap;
    }
}
