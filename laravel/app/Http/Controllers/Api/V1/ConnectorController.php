<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\ExternalTools\AssembleExternalTool;
use App\Enums\ExternalToolKind;
use App\Http\Requests\Api\V1\StoreConnectorRequest;
use App\Http\Requests\Api\V1\UpdateConnectorRequest;
use App\Http\Resources\Api\V1\ExternalToolResource;
use App\Models\ExternalTool;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ConnectorController extends ApiController
{
    public function __construct(
        WorkspaceContext $workspaceContext,
        private readonly AssembleExternalTool $assembler,
    ) {
        parent::__construct($workspaceContext);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $tools = ExternalTool::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('kind', ExternalToolKind::HttpConnector)
            ->orderBy('slug')
            ->paginate($this->perPage($request->integer('per_page') ?: null));

        return ExternalToolResource::collection($tools);
    }

    public function store(StoreConnectorRequest $request): ExternalToolResource
    {
        $attributes = $this->assembler->attributes($request->validated());

        $tool = ExternalTool::query()->create([
            ...$attributes,
            'workspace_id' => $this->workspaceId(),
            'kind' => ExternalToolKind::HttpConnector,
        ]);

        return new ExternalToolResource($tool);
    }

    public function show(int $connector): ExternalToolResource
    {
        return new ExternalToolResource($this->findConnector($connector));
    }

    public function update(UpdateConnectorRequest $request, int $connector): ExternalToolResource
    {
        $tool = $this->findConnector($connector);
        $tool->update($this->assembler->attributes($request->validated(), $tool));

        return new ExternalToolResource($tool);
    }

    public function destroy(int $connector): Response
    {
        $this->findConnector($connector)->delete();

        return response()->noContent();
    }

    private function findConnector(int $id): ExternalTool
    {
        return ExternalTool::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('kind', ExternalToolKind::HttpConnector)
            ->findOrFail($id);
    }
}
