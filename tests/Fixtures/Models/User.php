<?php

namespace Packstub\Agents\Tests\Fixtures\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
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
}
