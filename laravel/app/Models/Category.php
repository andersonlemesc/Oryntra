<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int         $id
 * @property int         $workspace_id
 * @property string      $name
 * @property string      $slug
 * @property string|null $description
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 * @property Workspace   $workspace
 */
class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'slug' => 'string',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category', 'name');
    }

    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });

        static::updating(function (Category $category): void {
            if ($category->isDirty('name') && ! $category->isDirty('slug')) {
                $category->slug = Str::slug($category->name);
            }
        });
    }
}