<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GoogleCalendarConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int                $id
 * @property int                $workspace_id
 * @property string             $connection_uuid
 * @property string             $label
 * @property string             $google_email
 * @property string|null        $google_user_id
 * @property string|null        $access_token
 * @property string|null        $refresh_token
 * @property string             $token_type
 * @property Carbon|null        $expires_at
 * @property array<int, string> $scopes
 * @property string|null        $default_calendar_id
 * @property bool               $default_notify_attendees
 * @property bool               $is_active
 * @property string|null        $last_error
 * @property Carbon|null        $last_used_at
 * @property int|null           $created_by_user_id
 */
#[Fillable([
    'workspace_id',
    'connection_uuid',
    'label',
    'google_email',
    'google_user_id',
    'access_token',
    'refresh_token',
    'token_type',
    'expires_at',
    'scopes',
    'default_calendar_id',
    'default_notify_attendees',
    'is_active',
    'last_error',
    'last_used_at',
    'created_by_user_id',
])]
#[Hidden(['access_token', 'refresh_token'])]
class GoogleCalendarConnection extends Model
{
    /** @use HasFactory<GoogleCalendarConnectionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function booted(): void
    {
        self::creating(function (GoogleCalendarConnection $connection): void {
            if (blank($connection->connection_uuid)) {
                $connection->connection_uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<GoogleCalendarAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(GoogleCalendarAuditLog::class);
    }

    /**
     * @param Builder<GoogleCalendarConnection> $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof Carbon
            && $this->expires_at->isPast();
    }

    public function hasRefreshToken(): bool
    {
        return filled($this->refresh_token);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'is_active' => 'boolean',
            'default_notify_attendees' => 'boolean',
        ];
    }
}
