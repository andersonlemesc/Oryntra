<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlaygroundMessageRole;
use App\Enums\PlaygroundMessageStatus;
use App\Models\PlaygroundConversation;
use App\Models\PlaygroundMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaygroundMessage>
 */
class PlaygroundMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'playground_conversation_id' => PlaygroundConversation::factory(),
            'role' => PlaygroundMessageRole::User,
            'content' => fake()->sentence(),
            'status' => null,
            'specialist_id' => null,
            'trace' => null,
            'usage' => null,
            'response' => null,
            'error_message' => null,
        ];
    }

    public function assistant(): self
    {
        return $this->state(fn (): array => [
            'role' => PlaygroundMessageRole::Assistant,
            'content' => null,
            'status' => PlaygroundMessageStatus::Pending,
        ]);
    }
}
