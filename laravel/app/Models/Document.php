<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int                       $id
 * @property int                       $workspace_id
 * @property string                    $category
 * @property string                    $title
 * @property string|null               $description
 * @property string                    $original_filename
 * @property string                    $filename
 * @property string                    $mime_type
 * @property int                       $size_bytes
 * @property string                    $path
 * @property array<string, mixed>|null $metadata
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    private const MIME_MAP = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $document): void {
            if (blank($document->path)) {
                return;
            }
            $document->filename = basename($document->path);
            $extension = strtolower(pathinfo($document->path, PATHINFO_EXTENSION));
            $document->mime_type = self::MIME_MAP[$extension] ?? 'application/octet-stream';
        });

        static::created(function (self $document): void {
            $document->updateFileSize();
        });

        static::updating(function (self $document): void {
            if ($document->isDirty('path') && filled($document->path)) {
                $document->filename = basename($document->path);
                $extension = strtolower(pathinfo($document->path, PATHINFO_EXTENSION));
                $document->mime_type = self::MIME_MAP[$extension] ?? 'application/octet-stream';
            }
        });

        static::updated(function (self $document): void {
            if ($document->isDirty('path')) {
                $document->updateFileSize();
            }
        });
    }

    protected function updateFileSize(): void
    {
        try {
            if (filled($this->path) && Storage::disk('s3')->exists($this->path)) {
                $size = Storage::disk('s3')->size($this->path);
                if ($size !== false) {
                    $this->forceFill(['size_bytes' => $size])->saveQuietly();
                }
            }
        } catch (\Throwable) {
        }
    }

    protected $fillable = [
        'workspace_id',
        'category',
        'title',
        'description',
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

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @param  Builder<Document> $query
     * @return Builder<Document>
     */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Documents in customer-sendable categories (excludes AI-knowledge-only docs).
     *
     * @param  Builder<Document> $query
     * @return Builder<Document>
     */
    public function scopeSendable(Builder $query): Builder
    {
        return $query->whereIn('category', DocumentCategory::sendableValues());
    }

    public function isSendable(): bool
    {
        return DocumentCategory::tryFrom($this->category)?->isSendable() ?? false;
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
     * @return array{id:int,title:string,category:string,original_filename:string,mime_type:string,size_bytes:int}
     */
    public function toAgentPayload(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
        ];
    }
}
