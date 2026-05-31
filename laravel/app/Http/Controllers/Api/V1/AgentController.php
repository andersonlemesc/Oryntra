<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Agents\CreateAgentWithDefaults;
use App\Http\Requests\Api\V1\StoreAgentRequest;
use App\Http\Requests\Api\V1\UpdateAgentRequest;
use App\Http\Resources\Api\V1\AgentResource;
use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AgentController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $agents = Agent::query()
            ->where('workspace_id', $this->workspaceId())
            ->withCount('specialists')
            ->orderByDesc('id')
            ->paginate($this->perPage($request->integer('per_page') ?: null));

        return AgentResource::collection($agents);
    }

    public function store(StoreAgentRequest $request, CreateAgentWithDefaults $action): AgentResource
    {
        $agent = $action->execute([
            ...$request->validated(),
            'workspace_id' => $this->workspaceId(),
        ]);

        return new AgentResource($agent->loadCount('specialists'));
    }

    public function show(int $agent): AgentResource
    {
        $model = $this->findInWorkspace(Agent::class, $agent);

        return new AgentResource($model->loadCount('specialists'));
    }

    public function update(UpdateAgentRequest $request, int $agent): AgentResource
    {
        $model = $this->findInWorkspace(Agent::class, $agent);
        $model->update($request->validated());

        return new AgentResource($model->loadCount('specialists'));
    }

    public function destroy(int $agent): Response
    {
        $this->findInWorkspace(Agent::class, $agent)->delete();

        return response()->noContent();
    }
}
