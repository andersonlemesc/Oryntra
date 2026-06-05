<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContactMemorySource;
use App\Enums\ContactMemoryType;
use App\Models\Contact;
use App\Models\ContactMemory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactMemory>
 */
class ContactMemoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $contact = Contact::factory()->create();

        return [
            'contact_id' => $contact->id,
            'workspace_id' => $contact->workspace_id,
            'type' => ContactMemoryType::Fact->value,
            'content' => fake()->sentence(),
            'source' => ContactMemorySource::Manual->value,
            'confidence' => null,
            'conversation_id' => null,
            'agent_run_id' => null,
            'author_user_id' => null,
            'expires_at' => null,
        ];
    }
}
