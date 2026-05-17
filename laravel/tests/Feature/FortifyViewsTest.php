<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('renders the Fortify login view', function () {
    get('/login')
        ->assertOk()
        ->assertSee('Oryntra')
        ->assertSee('Entrar');
});

it('renders enabled Fortify auth views', function (string $path) {
    get($path)
        ->assertOk()
        ->assertSee('Oryntra');
})->with([
    '/register',
    '/forgot-password',
]);

it('keeps Fortify login routes registered', function () {
    expect(Route::has('login'))->toBeTrue()
        ->and(Route::has('login.store'))->toBeTrue()
        ->and(Route::has('filament.admin.auth.login'))->toBeTrue();
});

it('creates an account through Fortify registration', function () {
    post(route('register.store'), [
        'name' => 'Anderson Lemes',
        'email' => 'anderson@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect('/admin');

    expect(User::where('email', 'anderson@example.com')->exists())->toBeTrue();
});
