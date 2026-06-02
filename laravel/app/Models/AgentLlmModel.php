<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AgentLlmModelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int         $id
 * @property int         $agent_llm_key_id
 * @property string      $model_id
 * @property string|null $label
 * @property Carbon|null $synced_at
 */
#[Fillable([
    'agent_llm_key_id',
    'model_id',
    'label',
    'synced_at',
])]
class AgentLlmModel extends Model
{
    /** @use HasFactory<AgentLlmModelFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<AgentLlmKey, $this>
     */
    public function key(): BelongsTo
    {
        return $this->belongsTo(AgentLlmKey::class, 'agent_llm_key_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
