<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property int                       $id
 * @property int                       $workspace_id
 * @property int                       $product_id
 * @property string                    $original_filename
 * @property string                    $filename
 * @property string                    $mime_type
 * @property int                       $size_bytes
 * @property string                    $path
 * @property array<string, mixed>|null $metadata
 */
class ProductDocument extends Model
{
    /** @use HasFactory<ProductDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'product_id',
        'original_filename',
        'filename',
        'mime_type',
        'size_bytes',
        'path',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function url(): string
    {
        return Storage::disk('s3')->url($this->path);
    }

    public function temporaryUrl(int $minutes = 30): string
    {
        return Storage::disk('s3')->temporaryUrl($this->path, now()->addMinutes($minutes));
    }

    /**
     * @return array{id:int,original_filename:string,mime_type:string,size_bytes:int}
     */
    public function toAgentPayload(): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
        ];
    }
}