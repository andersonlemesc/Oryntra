<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    // TelescopeServiceProvider is dev-only; AppServiceProvider registers it
    // conditionally so production (composer --no-dev) does not require Telescope.
];
