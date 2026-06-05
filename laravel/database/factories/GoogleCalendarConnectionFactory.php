<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoogleCalendarConnection>
 */
class GoogleCalendarConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'connection_uuid' => fake()->uuid(),
            'label' => fake()->company() . ' Calendar',
            'google_email' => fake()->unique()->safeEmail(),
            'google_user_id' => (string) fake()->unique()->numberBetween(100000000000, 999999999999),
            'access_token' => 'ya29.' . Str::random(80),
            'refresh_token' => '1//' . Str::random(64),
            'token_type' => 'Bearer',
            'expires_at' => now()->addHour(),
            'scopes' => [
                'https://www.googleapis.com/auth/calendar',
                'https://www.googleapis.com/auth/calendar.events',
            ],
            'default_calendar_id' => 'primary',
            'default_notify_attendees' => true,
            'is_active' => true,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function expired(): self
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}
