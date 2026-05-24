<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int                       $id
 * @property int                       $workspace_id
 * @property int|null                  $category_id
 * @property string                    $name
 * @property string|null               $sku
 * @property string|null               $description
 * @property float|null                $price
 * @property array<string, mixed>|null $metadata
 * @property bool                      $active
 * @property Carbon                    $created_at
 * @property Carbon                    $updated_at
 * @property Workspace                 $workspace
 * @property Category|null             $category
 */
class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'category_id',
        'name',
        'sku',
        'description',
        'price',
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

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @param  Builder<Product> $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<Product> $query
     * @return Builder<Product>
     */
    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->whereHas('category', function (Builder $categoryQuery) use ($category): void {
            $categoryQuery->where('name', $category)
                ->orWhere('slug', $category);
        });
    }

    /**
     * @param  Builder<Product> $query
     * @return Builder<Product>
     */
    public function scopeBySearch(Builder $query, string $search): Builder
    {
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->where(function (Builder $q) use ($operator, $search): void {
            $q->where('name', $operator, "%{$search}%")
                ->orWhere('description', $operator, "%{$search}%")
                ->orWhere('sku', $operator, "%{$search}%");
        });
    }

    /**
     * @param  Builder<Product> $query
     * @return Builder<Product>
     */
    public function scopePriceRange(Builder $query, ?float $min = null, ?float $max = null): Builder
    {
        if ($min !== null) {
            $query->where('price', '>=', $min);
        }
        if ($max !== null) {
            $query->where('price', '<=', $max);
        }

        return $query;
    }

    /**
     * @return array{id:int,name:string,sku:string|null,description:string|null,price:float|null,category:string|null}
     */
    public function toAgentPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'price' => $this->price,
            'category' => $this->category?->name,
        ];
    }
}
