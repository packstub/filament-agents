<?php

namespace Packstub\Agents\Support;

use Filament\FilamentServiceProvider;

/** What the app runs on, for the parts of the package that exist only with Filament (assets, the embedded table, the panel hints). */
class Installed
{
    /** Filament is installed and its service provider is loaded — not merely on the autoloader. */
    public static function filament(): bool
    {
        return class_exists(FilamentServiceProvider::class) && app()->providerIsLoaded(FilamentServiceProvider::class);
    }
}
