<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreSpecialistRequest;
use App\Http\Requests\Api\V1\UpdateSpecialistRequest;
use App\Http\Resources\Api\V1\SpecialistResource;
use App\Models\Agent;
use App\Models\AgentSpecialist;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SpecialistController extends ApiController
{
    public function index(int $agent): AnonymousResourceCollection
    {
        $model = $this->findInWorkspace(Agent::class, $agent);

        return SpecialistResource::collection(
            $model->specialists()->orderBy('priority')->orderBy('id')->get()
        );
    }

    public function store(StoreSpecialistRequest $request, int $agent): SpecialistResource
    {
        $model = $this->findInWorkspace(Agent::class, $agent);

        $specialist = $model->specialists()->create([
            ...$request->validated(),
            'workspace_id' => $this->workspaceId(),
        ]);

        return new SpecialistResource($specialist->refresh());
    }

    public function show(int $specialist): SpecialistResource
    {
        return new SpecialistResource($this->findInWorkspace(AgentSpecialist::class, $specialist));
    }

    public function update(UpdateSpecialistRequest $request, int $specialist): SpecialistResource
    {
        $model = $this->findInWorkspace(AgentSpecialist::class, $specialist);
        $model->update($request->validated());

        return new SpecialistResource($model);
    }

    public function destroy(int $specialist): Response
    {
        $this->findInWorkspace(AgentSpecialist::class, $specialist)->delete();

        return response()->noContent();
    }
}
