<?php

declare(strict_types=1);

use App\Actions\Contacts\ResolveOrCreateContact;
use App\Models\ChatwootConnection;
use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates a contact from a Chatwoot sender payload', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();

    $sender = [
        'id' => 42,
        'name' => 'Anderson Lemes',
        'email' => 'anderson@example.com',
        'phone_number' => '+5511999990000',
        'identifier' => 'wa:55119999',
        'thumbnail' => 'https://chatwoot.test/avatar/42.png',
        'additional_attributes' => ['city' => 'Florianopolis'],
        'custom_attributes' => ['plano' => 'premium'],
    ];

    $contact = app(ResolveOrCreateContact::class)->execute(
        workspaceId: $workspace->id,
        chatwootConnectionId: $connection->id,
        sender: $sender,
    );

    expect($contact->name)->toBe('Anderson Lemes')
        ->and($contact->email)->toBe('anderson@example.com')
        ->and($contact->phone_number)->toBe('+5511999990000')
        ->and($contact->identifier)->toBe('wa:55119999')
        ->and($contact->thumbnail)->toBe('https://chatwoot.test/avatar/42.png')
        ->and($contact->additional_attributes)->toBe(['city' => 'Florianopolis'])
        ->and($contact->chatwoot_custom_attributes)->toBe(['plano' => 'premium'])
        ->and($contact->chatwoot_contact_id)->toBe(42)
        ->and($contact->lead_status)->toBe('new')
        ->and($contact->first_seen_at)->not->toBeNull()
        ->and($contact->last_message_at)->not->toBeNull();
});

it('does not duplicate the contact when called twice for the same sender', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();

    $sender = ['id' => 42, 'name' => 'Anderson'];

    app(ResolveOrCreateContact::class)->execute($workspace->id, $connection->id, $sender);
    app(ResolveOrCreateContact::class)->execute($workspace->id, $connection->id, $sender);

    expect(Contact::query()->count())->toBe(1);
});

it('updates last_message_at to the newer timestamp on repeat resolves', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();

    $earlier = Carbon::parse('2026-05-23T10:00:00Z');
    $later = Carbon::parse('2026-05-23T11:30:00Z');

    $contact = app(ResolveOrCreateContact::class)->execute(
        $workspace->id,
        $connection->id,
        ['id' => 42, 'name' => 'Anderson'],
        $earlier,
    );

    app(ResolveOrCreateContact::class)->execute(
        $workspace->id,
        $connection->id,
        ['id' => 42, 'name' => 'Anderson Lemes'],
        $later,
    );

    $contact->refresh();

    expect($contact->name)->toBe('Anderson Lemes')
        ->and($contact->last_message_at?->equalTo($later))->toBeTrue();
});

it('isolates contacts across workspaces with the same chatwoot_contact_id', function () {
    $workspaceA = Workspace::factory()->create();
    $workspaceB = Workspace::factory()->create();
    $connectionA = ChatwootConnection::factory()->for($workspaceA)->create();
    $connectionB = ChatwootConnection::factory()->for($workspaceB)->create();

    $sender = ['id' => 42, 'name' => 'Cliente comum'];

    $contactA = app(ResolveOrCreateContact::class)->execute($workspaceA->id, $connectionA->id, $sender);
    $contactB = app(ResolveOrCreateContact::class)->execute($workspaceB->id, $connectionB->id, $sender);

    expect($contactA->id)->not->toBe($contactB->id)
        ->and(Contact::query()->count())->toBe(2);
});

it('throws when sender id is missing', function () {
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();

    expect(fn () => app(ResolveOrCreateContact::class)->execute(
        $workspace->id,
        $connection->id,
        ['name' => 'sem id'],
    ))->toThrow(InvalidArgumentException::class);
});
