<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int                       $id
 * @property int                       $workspace_id
 * @property int|null                  $category_id
 * @property string                    $name
 * @property string|null               $sku
 * @property string|null               $description
 * @property float|null                $price
 * @property array<string, mixed>|null $metadata
 * @property array<int, string>|null   $tags
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
        'tags',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'active' => 'bool',
            'metadata' => 'array',
            'tags' => 'array',
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
     * Agents this product is scoped to. No links = global (visible to every agent).
     *
     * @return BelongsToMany<Agent, $this>
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class)->withTimestamps();
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
     * Limit to products visible to the given agent: those explicitly linked to it,
     * plus global products (linked to no agent at all).
     *
     * @param  Builder<Product> $query
     * @return Builder<Product>
     */
    public function scopeForAgent(Builder $query, int $agentId): Builder
    {
        return $query->where(function (Builder $q) use ($agentId): void {
            $q->whereHas('agents', function (Builder $agentQuery) use ($agentId): void {
                $agentQuery->whereKey($agentId);
            })->orWhereDoesntHave('agents');
        });
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
        $operator = self::driverNameForQuery($query) === 'pgsql' ? 'ilike' : 'like';

        return $query->where(function (Builder $q) use ($operator, $search): void {
            $q->where('name', $operator, "%{$search}%")
                ->orWhere('description', $operator, "%{$search}%")
                ->orWhere('sku', $operator, "%{$search}%");

            self::orWhereTagMatches($q, $search);
        });
    }

    /**
     * Add an OR clause matching products whose `tags` array contains the term.
     * Postgres: accent-insensitive ILIKE over each jsonb array element.
     * Other drivers (SQLite in tests): LIKE over the serialized JSON column.
     *
     * @param Builder<Product> $query
     */
    public static function orWhereTagMatches(Builder $query, string $term): void
    {
        if (self::driverNameForQuery($query) === 'pgsql') {
            $query->orWhereRaw(
                'EXISTS (SELECT 1 FROM jsonb_array_elements_text(tags) AS tag WHERE unaccent(lower(tag)) ILIKE unaccent(lower(?)))',
                ['%' . $term . '%'],
            );
        } else {
            $query->orWhereRaw('lower(tags) like ?', ['%' . mb_strtolower($term) . '%']);
        }
    }

    /**
     * @param Builder<Product> $query
     */
    private static function driverNameForQuery(Builder $query): string
    {
        return DB::connection($query->getModel()->getConnectionName())->getDriverName();
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
     * @return HasMany<ProductDocument>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ProductDocument::class);
    }

    /**
     * @return array{id:int,name:string,sku:string|null,description:string|null,price:float|null,category:string|null,documents:list<array{id:int,original_filename:string,mime_type:string,size_bytes:int}>}
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
            'documents' => $this->relationLoaded('documents')
                ? $this->documents->map(fn (ProductDocument $d) => $d->toAgentPayload())->all()
                : [],
        ];
    }
}
