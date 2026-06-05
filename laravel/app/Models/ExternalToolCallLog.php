<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int                  $id
 * @property int                  $workspace_id
 * @property int                  $external_tool_id
 * @property int                  $agent_run_id
 * @property int|null             $specialist_id
 * @property string               $tool_slug
 * @property array<string, mixed> $request_args
 * @property int|null             $http_status
 * @property bool                 $success
 * @property int                  $latency_ms
 * @property string|null          $response_excerpt
 * @property string|null          $error
 */
#[Fillable([
    'workspace_id',
    'external_tool_id',
    'agent_run_id',
    'specialist_id',
    'tool_slug',
    'request_args',
    'http_status',
    'success',
    'latency_ms',
    'response_excerpt',
    'error',
])]
class ExternalToolCallLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<ExternalTool, $this>
     */
    public function externalTool(): BelongsTo
    {
        return $this->belongsTo(ExternalTool::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_args' => 'array',
            'success' => 'boolean',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
        ];
    }
}
