<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentLlmProvider;
use App\Enums\AgentResponseMode;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->company() . ' Agent',
            'description' => fake()->sentence(),
            'status' => AgentStatus::Inactive,
            'locale' => 'en',
            'timezone' => 'UTC',
            'response_mode' => AgentResponseMode::Automatic,
            'llm_provider' => AgentLlmProvider::OpenAI,
            'llm_model' => 'gpt-4.1-mini',
            'llm_temperature' => 0.2,
            'llm_max_tokens' => 1024,
            'system_prompt' => 'You are a helpful assistant.',
            'behavior_prompt' => null,
            'fallback_message' => null,
            'debounce_config' => [
                'enabled' => true,
                'window_seconds' => 8,
                'max_wait_seconds' => 20,
                'max_messages' => 10,
            ],
            'media_policy' => [
                'audio' => ['mode' => 'transcribe'],
                'image' => ['mode' => 'vision'],
                'video' => ['mode' => 'fallback'],
                'file' => ['mode' => 'fallback'],
                'max_attachment_mb' => 20,
            ],
            'guard_config' => [
                'block_sensitive_data' => true,
                'block_prompt_injection' => true,
                'require_rag_for_answers' => false,
                'handoff_on_low_confidence' => true,
                'low_confidence_threshold' => 0.4,
            ],
            'rag_config' => [
                'enabled' => false,
                'top_k' => 5,
                'min_score' => 0.7,
                'answer_only_with_context' => false,
            ],
            'runtime_config' => [
                'graph' => 'default_support_agent',
                'streaming' => false,
                'stream_modes' => ['updates'],
                'checkpointing' => true,
                'long_term_memory' => false,
                'human_in_the_loop' => false,
                'tool_call_limit' => 8,
            ],
        ];
    }

    public function active(): self
    {
        return $this->state(fn (): array => ['status' => AgentStatus::Active]);
    }
}
