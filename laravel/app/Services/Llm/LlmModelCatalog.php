<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Enums\AgentLlmProvider;
use App\Models\AgentLlmKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fetches the model catalog from an LLM provider and persists it on the key.
 *
 * Resolves the base URL from the key (falling back to the provider default) so
 * any OpenAI-compatible endpoint (Groq, Together, Ollama, vLLM…) can be synced.
 */
final class LlmModelCatalog
{
    private const TIMEOUT = 30;

    private const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * Sync the available models for the key. Returns the number of models stored.
     * Throws on HTTP/transport failures so the caller can surface a notification.
     */
    public function sync(AgentLlmKey $key): int
    {
        $models = $this->fetchModels($key);

        DB::transaction(function () use ($key, $models): void {
            $now = now();

            foreach ($models as $modelId) {
                $key->models()->updateOrCreate(
                    ['model_id' => $modelId],
                    ['label' => $modelId, 'synced_at' => $now],
                );
            }

            $key->models()
                ->whereNotIn('model_id', $models)
                ->delete();
        });

        return count($models);
    }

    /**
     * @return array<int, string>
     */
    private function fetchModels(AgentLlmKey $key): array
    {
        $provider = $key->provider instanceof AgentLlmProvider
            ? $key->provider
            : AgentLlmProvider::from((string) $key->provider);

        $base = rtrim($key->base_url ?: (string) $provider->defaultBaseUrl(), '/');

        return match ($provider) {
            AgentLlmProvider::Anthropic => $this->fetchAnthropic($base, (string) $key->api_key),
            AgentLlmProvider::Gemini => $this->fetchGemini($base, (string) $key->api_key),
            AgentLlmProvider::OpenAI, AgentLlmProvider::Local => $this->fetchOpenAiCompatible($base, (string) $key->api_key),
        };
    }

    /**
     * @return array<int, string>
     */
    private function fetchOpenAiCompatible(string $base, string $apiKey): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withToken($apiKey)
            ->acceptJson()
            ->get("{$base}/models")
            ->throw();

        return $this->sortedUnique(
            collect((array) data_get($response->json(), 'data', []))
                ->pluck('id')
                ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
                ->all(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function fetchAnthropic(string $base, string $apiKey): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => self::ANTHROPIC_VERSION,
            ])
            ->acceptJson()
            ->get("{$base}/v1/models")
            ->throw();

        return $this->sortedUnique(
            collect((array) data_get($response->json(), 'data', []))
                ->pluck('id')
                ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
                ->all(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function fetchGemini(string $base, string $apiKey): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->acceptJson()
            ->get("{$base}/v1beta/models", ['key' => $apiKey])
            ->throw();

        return $this->sortedUnique(
            collect((array) data_get($response->json(), 'models', []))
                ->filter(fn (mixed $model): bool => in_array(
                    'generateContent',
                    (array) data_get($model, 'supportedGenerationMethods', []),
                    true,
                ))
                ->pluck('name')
                ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
                ->map(fn (string $name): string => preg_replace('#^models/#', '', $name) ?? $name)
                ->all(),
        );
    }

    /**
     * @param  array<int, string> $models
     * @return array<int, string>
     */
    private function sortedUnique(array $models): array
    {
        $models = array_values(array_unique($models));
        sort($models);

        return $models;
    }
}
