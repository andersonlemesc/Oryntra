<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChatwootConnection;
use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = now();

        return [
            'workspace_id' => Workspace::factory(),
            'chatwoot_connection_id' => ChatwootConnection::factory(),
            'chatwoot_contact_id' => fake()->unique()->numberBetween(1, 100000),
            'identifier' => null,
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone_number' => fake()->e164PhoneNumber(),
            'thumbnail' => null,
            'additional_attributes' => [],
            'chatwoot_custom_attributes' => [],
            'lead_status' => 'new',
            'lead_score' => null,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_message_at' => $now,
            'synced_at' => null,
        ];
    }
}
