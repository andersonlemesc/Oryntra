<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(function (Request $request): bool {
            $user = $request->user();

            return $user instanceof User && Gate::forUser($user)->allows('viewHorizon');
        });
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function (User $user): bool {
            return $user->isSuperAdmin();
        });
    }
}
