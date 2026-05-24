<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Products\DownloadTemplateController;
use App\Http\Controllers\Setup\PlatformSetupController;
use App\Http\Middleware\EnsurePlatformSetupNeeded;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): RedirectResponse {
    if (auth()->check()) {
        return redirect()->to('/admin');
    }

    if (! User::query()->exists()) {
        return redirect()->to('/register');
    }

    return redirect()->to(route('login'));
});

Route::get(config('invitations.accept_path') . '/{token}', [InvitationController::class, 'show'])
    ->name('invitation.show');
Route::post(config('invitations.accept_path') . '/{token}', [InvitationController::class, 'accept'])
    ->name('invitation.accept');

Route::get('/download/products-template', DownloadTemplateController::class)
    ->name('download.products-template');

Route::middleware(['auth', EnsurePlatformSetupNeeded::class])
    ->prefix('setup')
    ->name('setup.')
    ->group(function (): void {
        Route::get('/platform', [PlatformSetupController::class, 'show'])->name('platform.show');
        Route::post('/platform', [PlatformSetupController::class, 'store'])->name('platform.store');
    });
