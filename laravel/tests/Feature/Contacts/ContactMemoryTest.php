<?php

declare(strict_types=1);

use App\Enums\ContactMemorySource;
use App\Enums\ContactMemoryType;
use App\Models\Contact;
use App\Models\ContactMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('persists a manual memory linked to a contact', function () {
    $contact = Contact::factory()->create();

    $memory = ContactMemory::factory()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $contact->workspace_id,
        'type' => ContactMemoryType::Preference->value,
        'content' => 'Cliente prefere bike eletrica urbana',
        'source' => ContactMemorySource::Manual->value,
    ]);

    $relatedContact = $memory->contact;
    assert($relatedContact instanceof Contact);

    expect($memory->type)->toBe(ContactMemoryType::Preference)
        ->and($memory->source)->toBe(ContactMemorySource::Manual)
        ->and($memory->content)->toBe('Cliente prefere bike eletrica urbana')
        ->and($relatedContact->is($contact))->toBeTrue();
});

it('cascades delete when the contact is removed', function () {
    $contact = Contact::factory()->create();
    ContactMemory::factory()->count(3)->create([
        'contact_id' => $contact->id,
        'workspace_id' => $contact->workspace_id,
    ]);

    expect(ContactMemory::query()->where('contact_id', $contact->id)->count())->toBe(3);

    $contact->forceDelete();

    expect(ContactMemory::query()->where('contact_id', $contact->id)->count())->toBe(0);
});

it('exposes the memories relation on the contact', function () {
    $contact = Contact::factory()->create();
    ContactMemory::factory()->count(2)->create([
        'contact_id' => $contact->id,
        'workspace_id' => $contact->workspace_id,
    ]);

    expect($contact->memories()->count())->toBe(2);
});
