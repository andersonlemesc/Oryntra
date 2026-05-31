<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\ExternalTools\AssembleExternalTool;
use App\Enums\ExternalToolKind;
use App\Http\Requests\Api\V1\StoreMcpServerRequest;
use App\Http\Requests\Api\V1\UpdateMcpServerRequest;
use App\Http\Resources\Api\V1\ExternalToolResource;
use App\Models\ExternalTool;
use App\Services\MCP\McpHttpClient;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class McpServerController extends ApiController
{
    public function __construct(
        WorkspaceContext $workspaceContext,
        private readonly AssembleExternalTool $assembler,
    ) {
        parent::__construct($workspaceContext);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $servers = ExternalTool::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('kind', ExternalToolKind::Mcp)
            ->orderBy('slug')
            ->paginate($this->perPage($request->integer('per_page') ?: null));

        return ExternalToolResource::collection($servers);
    }

    public function store(StoreMcpServerRequest $request): ExternalToolResource
    {
        $attributes = $this->assembler->attributes($request->validated());

        $server = ExternalTool::query()->create([
            ...$attributes,
            'workspace_id' => $this->workspaceId(),
            'kind' => ExternalToolKind::Mcp,
        ]);

        return new ExternalToolResource($server);
    }

    public function show(int $mcpServer): ExternalToolResource
    {
        return new ExternalToolResource($this->findServer($mcpServer));
    }

    public function update(UpdateMcpServerRequest $request, int $mcpServer): ExternalToolResource
    {
        $server = $this->findServer($mcpServer);
        $server->update($this->assembler->attributes($request->validated(), $server));

        return new ExternalToolResource($server);
    }

    public function destroy(int $mcpServer): Response
    {
        $this->findServer($mcpServer)->delete();

        return response()->noContent();
    }

    /**
     * Live tool discovery against the MCP server (handshake + tools/list).
     */
    public function tools(int $mcpServer, McpHttpClient $client): JsonResponse
    {
        $server = $this->findServer($mcpServer);

        try {
            $session = $client->initialize($server);
            $tools = $client->listTools($server, $session);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Falha ao conectar no servidor MCP: ' . $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['data' => $tools]);
    }

    private function findServer(int $id): ExternalTool
    {
        return ExternalTool::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('kind', ExternalToolKind::Mcp)
            ->findOrFail($id);
    }
}
