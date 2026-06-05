<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('allows only super admins to access horizon', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $regularUser = User::factory()->create(['is_super_admin' => false]);

    expect(Gate::forUser($superAdmin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($regularUser)->allows('viewHorizon'))->toBeFalse();

    $superAdminRequest = Request::create('/horizon');
    $superAdminRequest->setUserResolver(fn (): User => $superAdmin);

    expect(Horizon::check($superAdminRequest))->toBeTrue();

    $regularUserRequest = Request::create('/horizon');
    $regularUserRequest->setUserResolver(fn (): User => $regularUser);

    expect(Horizon::check($regularUserRequest))->toBeFalse();
});
