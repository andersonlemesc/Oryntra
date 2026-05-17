<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'base_url',
    'platform_token',
    'last_synced_at',
    'last_sync_status',
    'last_sync_error',
    'last_sync_summary',
])]
class ChatwootPlatformConnection extends Model
{
    /**
     * @var list<string>
     */
    protected $hidden = ['platform_token'];

    /**
     * Singleton accessor. Returns first row or new unsaved instance.
     */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self;
    }

    /**
     * @return array{api_access_token: string}
     */
    public function platformHeaders(): array
    {
        return ['api_access_token' => (string) $this->platform_token];
    }

    public function isConfigured(): bool
    {
        return filled($this->base_url) && filled($this->platform_token);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform_token' => 'encrypted',
            'last_synced_at' => 'datetime',
            'last_sync_summary' => 'array',
        ];
    }
}
