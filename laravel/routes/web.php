<?php

use App\Http\Controllers\Auth\InvitationController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): RedirectResponse {
    return redirect()->to(auth()->check() ? '/admin' : route('login'));
});

Route::get(config('invitations.accept_path').'/{token}', [InvitationController::class, 'show'])
    ->name('invitation.show');
Route::post(config('invitations.accept_path').'/{token}', [InvitationController::class, 'accept'])
    ->name('invitation.accept');
