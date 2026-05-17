<?php

namespace Database\Factories;

use App\Enums\ChatwootConnectionStatus;
use App\Models\ChatwootConnection;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChatwootConnection>
 */
class ChatwootConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'connection_uuid' => fake()->uuid(),
            'name' => fake()->company().' Chatwoot',
            'base_url' => 'https://chatwoot.example.com',
            'account_id' => fake()->unique()->numberBetween(1, 999999),
            'api_access_token' => Str::random(40),
            'webhook_secret' => Str::random(48),
            'status' => ChatwootConnectionStatus::Active,
        ];
    }
}
