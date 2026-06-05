<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int                  $id
 * @property int                  $workspace_id
 * @property int                  $google_calendar_connection_id
 * @property int|null             $agent_run_id
 * @property int|null             $specialist_id
 * @property string               $action
 * @property string|null          $calendar_id
 * @property string|null          $google_event_id
 * @property array<string, mixed> $request_args
 * @property bool                 $success
 * @property int|null             $http_status
 * @property int                  $latency_ms
 * @property string|null          $response_excerpt
 * @property string|null          $error
 * @property Carbon|null          $created_at
 */
#[Fillable([
    'workspace_id',
    'google_calendar_connection_id',
    'agent_run_id',
    'specialist_id',
    'action',
    'calendar_id',
    'google_event_id',
    'request_args',
    'success',
    'http_status',
    'latency_ms',
    'response_excerpt',
    'error',
])]
class GoogleCalendarAuditLog extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<GoogleCalendarConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleCalendarConnection::class, 'google_calendar_connection_id');
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_args' => 'array',
            'success' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (GoogleCalendarAuditLog $log): void {
            if (blank($log->created_at)) {
                $log->created_at = now();
            }
        });
    }
}
