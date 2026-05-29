<?php

declare(strict_types=1);

use App\Models\GoogleCalendarConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('persists tokens with encryption (not as plaintext)', function () {
    $connection = GoogleCalendarConnection::factory()->create([
        'access_token' => 'plaintext-access-token-123',
        'refresh_token' => 'plaintext-refresh-token-456',
    ]);

    $raw = $connection->getRawOriginal('access_token');
    expect($raw)->not->toBe('plaintext-access-token-123');
    expect(strlen($raw))->toBeGreaterThan(100);

    expect($connection->access_token)->toBe('plaintext-access-token-123');
    expect($connection->refresh_token)->toBe('plaintext-refresh-token-456');
});

it('isExpired returns true when expires_at is in the past', function () {
    $expired = GoogleCalendarConnection::factory()->expired()->create();
    $valid = GoogleCalendarConnection::factory()->create();

    expect($expired->isExpired())->toBeTrue();
    expect($valid->isExpired())->toBeFalse();
});

it('scopeActive filters inactive connections', function () {
    GoogleCalendarConnection::factory()->create();
    GoogleCalendarConnection::factory()->inactive()->create();

    expect(GoogleCalendarConnection::query()->active()->count())->toBe(1);
    expect(GoogleCalendarConnection::query()->count())->toBe(2);
});

it('exposes scopes as array', function () {
    $connection = GoogleCalendarConnection::factory()->create();

    expect($connection->scopes)->toBeArray();
    expect($connection->scopes)->toContain('https://www.googleapis.com/auth/calendar');
});
