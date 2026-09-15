<?php

namespace Packstub\Agents\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Widget extends Model
{
    protected $guarded = [];

    /** The workspace the widget belongs to once the panel has tenancy (Filament scopes the resource by it). */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
