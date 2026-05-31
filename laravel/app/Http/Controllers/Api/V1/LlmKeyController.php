<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreLlmKeyRequest;
use App\Http\Requests\Api\V1\UpdateLlmKeyRequest;
use App\Http\Resources\Api\V1\LlmKeyResource;
use App\Http\Resources\Api\V1\LlmModelResource;
use App\Models\AgentLlmKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LlmKeyController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $keys = AgentLlmKey::query()
            ->where('workspace_id', $this->workspaceId())
            ->withCount('models')
            ->orderByDesc('id')
            ->paginate($this->perPage($request->integer('per_page') ?: null));

        return LlmKeyResource::collection($keys);
    }

    public function store(StoreLlmKeyRequest $request): LlmKeyResource
    {
        $key = AgentLlmKey::query()->create([
            ...$request->validated(),
            'workspace_id' => $this->workspaceId(),
        ]);

        return new LlmKeyResource($key);
    }

    public function show(int $llmKey): LlmKeyResource
    {
        $key = $this->findInWorkspace(AgentLlmKey::class, $llmKey);

        return new LlmKeyResource($key->loadCount('models'));
    }

    public function update(UpdateLlmKeyRequest $request, int $llmKey): LlmKeyResource
    {
        $key = $this->findInWorkspace(AgentLlmKey::class, $llmKey);
        $key->update($request->validated());

        return new LlmKeyResource($key);
    }

    public function destroy(int $llmKey): Response
    {
        $this->findInWorkspace(AgentLlmKey::class, $llmKey)->delete();

        return response()->noContent();
    }

    public function models(int $llmKey): AnonymousResourceCollection
    {
        $key = $this->findInWorkspace(AgentLlmKey::class, $llmKey);

        return LlmModelResource::collection($key->models()->orderBy('model_id')->get());
    }
}
