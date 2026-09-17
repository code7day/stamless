<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'must_change_password', 'is_active', 'tenant_id', 'uuid', 'locale', 'timezone', 'is_super_admin', 'provider', 'provider_id', 'avatar_url'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAvatar, HasDefaultTenant, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasTenant, HasUuid, Notifiable;

    /**
     * Determine if the user can access the given Filament panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        if (! ($this->is_active ?? true)) {
            return false;
        }

        if ($panel->getId() === 'platform') {
            return false;
        }

        if ($panel->getId() === 'cms') {
            return $this->tenant_id !== null;
        }

        return false;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the default tenant for the user.
     */
    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->tenant;
    }

    /**
     * Get the tenants the user belongs to.
     */
    public function getTenants(Panel $panel): array|Collection
    {
        return $this->tenant ? collect([$this->tenant]) : collect();
    }

    /**
     * Check if the user can access a tenant.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return $this->tenant_id === $tenant->id;
    }

    /**
     * Get avatar url for Filament.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_url;
    }
}
