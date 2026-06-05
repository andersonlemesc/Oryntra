<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExternalToolKind;
use Database\Factories\ExternalToolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int                              $id
 * @property int                              $workspace_id
 * @property ExternalToolKind                 $kind
 * @property string                           $slug
 * @property string                           $label
 * @property string                           $description
 * @property bool                             $enabled
 * @property array<string, mixed>             $config
 * @property array<string, mixed>|string|null $credentials
 */
#[Fillable([
    'workspace_id',
    'kind',
    'slug',
    'label',
    'description',
    'enabled',
    'config',
    'credentials',
])]
#[Hidden(['credentials'])]
class ExternalTool extends Model
{
    /** @use HasFactory<ExternalToolFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<ExternalToolCallLog, $this>
     */
    public function callLogs(): HasMany
    {
        return $this->hasMany(ExternalToolCallLog::class);
    }

    /**
     * @param Builder<ExternalTool> $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * Normalized parameter schema the LLM fills (never includes secrets).
     *
     * @return array<string, mixed>
     */
    public function paramSchema(): array
    {
        $schema = $this->config['param_schema'] ?? [];

        return is_array($schema) ? $schema : [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ExternalToolKind::class,
            'enabled' => 'boolean',
            'config' => 'array',
            'credentials' => 'encrypted:array',
        ];
    }
}
