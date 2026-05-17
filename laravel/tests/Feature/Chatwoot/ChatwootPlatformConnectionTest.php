<?php

declare(strict_types=1);

use App\Models\ChatwootPlatformConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('returns new unsaved instance when no row exists', function () {
    $connection = ChatwootPlatformConnection::current();

    expect($connection)->toBeInstanceOf(ChatwootPlatformConnection::class)
        ->and($connection->exists)->toBeFalse()
        ->and($connection->isConfigured())->toBeFalse();
});

it('returns persisted singleton row when one exists', function () {
    $created = ChatwootPlatformConnection::create([
        'base_url' => 'https://chatwoot.test',
        'platform_token' => 'secret-platform-token',
    ]);

    $loaded = ChatwootPlatformConnection::current();

    expect($loaded->exists)->toBeTrue()
        ->and($loaded->id)->toBe($created->id)
        ->and($loaded->isConfigured())->toBeTrue()
        ->and($loaded->platform_token)->toBe('secret-platform-token');
});

it('encrypts the platform token in storage', function () {
    ChatwootPlatformConnection::create([
        'base_url' => 'https://chatwoot.test',
        'platform_token' => 'super-secret-token',
    ]);

    $rawToken = DB::table('chatwoot_platform_connections')->value('platform_token');

    expect($rawToken)
        ->not->toBe('super-secret-token')
        ->and(is_string($rawToken) && mb_strlen($rawToken) > 20)->toBeTrue();
});

it('hides platform_token in array output', function () {
    $connection = ChatwootPlatformConnection::create([
        'base_url' => 'https://chatwoot.test',
        'platform_token' => 'token-not-exposed',
    ]);

    expect($connection->toArray())->not->toHaveKey('platform_token');
});

it('builds platformHeaders with the api_access_token header', function () {
    $connection = new ChatwootPlatformConnection;
    $connection->platform_token = 'header-token';

    expect($connection->platformHeaders())->toBe(['api_access_token' => 'header-token']);
});
