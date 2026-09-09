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

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /** A workspace is the team the person owns (what Filament's HasTenants and the headless context both ask). */
    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Team && (int) $tenant->owner_id === (int) $this->getKey();
    }
}
