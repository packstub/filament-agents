<?php

namespace Packstub\Agents\Tests\Fixtures\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens;

    protected $guarded = [];

    protected $casts = ['is_admin' => 'bool'];

    /** Suspended people keep their rows and memberships but may not use the panel, as on a page. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->suspended_at === null;
    }

    /** A workspace is the team the person owns (what Filament's HasTenants and the headless context both ask). */
    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Team && (int) $tenant->owner_id === (int) $this->getKey();
    }
}
