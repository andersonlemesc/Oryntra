<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('allows GET /register when no users exist', function () {
    get('/register')->assertOk();
});

it('redirects GET /register to /login when at least one user exists', function () {
    User::factory()->create();

    get('/register')->assertRedirect(route('login'));
});

it('redirects POST /register to /login when at least one user exists', function () {
    User::factory()->create();

    post('/register', [
        'name' => 'New Admin',
        'email' => 'new@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
    ])->assertRedirect(route('login'));
});

it('redirects super admin without workspaces to /setup/platform after register', function () {
    post('/register', [
        'name' => 'First Admin',
        'email' => 'admin@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
    ])->assertRedirect(route('setup.platform.show'));

    $user = User::query()->where('email', 'admin@example.com')->firstOrFail();
    expect($user->is_super_admin)->toBeTrue();
});

it('redirects super admin to /admin after login when workspaces exist', function () {
    Workspace::factory()->create();
    $user = User::factory()->create([
        'is_super_admin' => true,
        'password' => bcrypt('StrongPass123!'),
    ]);

    post('/login', [
        'email' => $user->email,
        'password' => 'StrongPass123!',
    ])->assertRedirect('/admin');
});

it('blocks /setup/platform for non-super-admin users', function () {
    $user = User::factory()->create(['is_super_admin' => false]);

    actingAs($user)->get('/setup/platform')->assertNotFound();
});

it('redirects super admin away from /setup/platform when workspaces exist', function () {
    Workspace::factory()->create();
    $user = User::factory()->create(['is_super_admin' => true]);

    actingAs($user)->get('/setup/platform')->assertRedirect('/admin');
});

it('shows setup form for super admin without workspaces', function () {
    $user = User::factory()->create(['is_super_admin' => true]);

    actingAs($user)->get('/setup/platform')->assertOk();
});
