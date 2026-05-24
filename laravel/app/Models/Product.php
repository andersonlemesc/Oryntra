<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int                          $id
 * @property int                          $workspace_id
 * @property string                       $name
 * @property string|null                  $sku
 * @property string|null                  $description
 * @property float|null                   $price
 * @property string|null                  $category
 * @property array<string, mixed>|null   $metadata
 * @property bool                         $active
 * @property \Illuminate\Support\Carbon  $created_at
 * @property \Illuminate\Support\Carbon  $updated_at
 * @property Workspace                   $workspace
 */
class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'name',
        'sku',
        'description',
        'price',
        'category',
        'metadata',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'active' => 'bool',
            'metadata' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeBySearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'ilike', "%{$search}%")
              ->orWhere('description', 'ilike', "%{$search}%")
              ->orWhere('sku', 'ilike', "%{$search}%");
        });
    }

    public function scopePriceRange($query, ?float $min = null, ?float $max = null)
    {
        if ($min !== null) {
            $query->where('price', '>=', $min);
        }
        if ($max !== null) {
            $query->where('price', '<=', $max);
        }

        return $query;
    }

    public function toAgentPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'price' => $this->price,
            'category' => $this->category,
        ];
    }
}