<?php

declare(strict_types=1);

use App\Jobs\Chatwoot\SyncChatwootContactsJob;
use App\Models\ChatwootConnection;
use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates new contacts and updates existing ones from Chatwoot', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'admin_api_token' => 'admin-token',
    ]);
    $existing = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_contact_id' => 1,
        'name' => 'Antigo',
        'lead_status' => 'qualified',
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/contacts*' => Http::response([
            'payload' => [
                [
                    'id' => 1,
                    'name' => 'Anderson Lemes',
                    'email' => 'anderson@example.com',
                    'phone_number' => '+5511999990000',
                    'additional_attributes' => ['city' => 'Floripa'],
                    'custom_attributes' => ['plano' => 'premium'],
                ],
                [
                    'id' => 2,
                    'name' => 'Maria',
                    'email' => null,
                    'phone_number' => '+5511888880000',
                    'additional_attributes' => [],
                    'custom_attributes' => [],
                ],
            ],
            'meta' => ['count' => 2, 'current_page' => '1'],
        ]),
    ]);

    (new SyncChatwootContactsJob($connection->id))->handle();

    $existing->refresh();

    expect($existing->name)->toBe('Anderson Lemes')
        ->and($existing->email)->toBe('anderson@example.com')
        ->and($existing->chatwoot_custom_attributes)->toBe(['plano' => 'premium'])
        ->and($existing->lead_status)->toBe('qualified')
        ->and($existing->synced_at)->not->toBeNull()
        ->and(Contact::query()->where('chatwoot_contact_id', 2)->exists())->toBeTrue();
});

it('does nothing when admin token is missing', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'admin_api_token' => null,
    ]);

    Http::fake();

    (new SyncChatwootContactsJob($connection->id))->handle();

    Http::assertNothingSent();
    expect(Contact::query()->count())->toBe(0);
});

it('preserves local lead_status when remote does not provide it', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'admin_api_token' => 'admin-token',
    ]);
    $existing = Contact::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_contact_id' => 7,
        'lead_status' => 'won',
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/contacts*' => Http::response([
            'payload' => [
                ['id' => 7, 'name' => 'Cliente Convertido'],
            ],
            'meta' => ['count' => 1],
        ]),
    ]);

    (new SyncChatwootContactsJob($connection->id))->handle();

    $existing->refresh();

    expect($existing->lead_status)->toBe('won');
});
