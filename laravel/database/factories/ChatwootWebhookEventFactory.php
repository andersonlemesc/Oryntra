<?php

namespace Database\Factories;

use App\Models\ChatwootConnection;
use App\Models\ChatwootWebhookEvent;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatwootWebhookEvent>
 */
class ChatwootWebhookEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $workspace = Workspace::factory();

        return [
            'workspace_id' => $workspace,
            'chatwoot_connection_id' => ChatwootConnection::factory()->state([
                'workspace_id' => $workspace,
            ]),
            'event_name' => 'message_created',
            'chatwoot_account_id' => fake()->numberBetween(1, 999999),
            'conversation_id' => fake()->numberBetween(1, 999999),
            'chatwoot_message_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'payload' => [
                'event' => 'message_created',
            ],
            'signature' => fake()->sha256(),
            'status' => 'queued',
            'received_at' => now(),
        ];
    }
}
