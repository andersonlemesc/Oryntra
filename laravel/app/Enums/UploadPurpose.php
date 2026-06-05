<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a presigned upload is destined for. Drives storage path, allowed mime
 * types and which ability is required to request/confirm it.
 */
enum UploadPurpose: string
{
    case ProductDocument = 'product_document';
    case Document = 'document';
    case Knowledge = 'knowledge';

    /**
     * Ability required on the API token to use this upload purpose.
     */
    public function ability(): string
    {
        return match ($this) {
            self::ProductDocument, self::Document => 'media:write',
            self::Knowledge => 'knowledge:write',
        };
    }

    /**
     * Storage directory (within the workspace) for this purpose.
     */
    public function directory(int $workspaceId): string
    {
        return match ($this) {
            self::ProductDocument, self::Document => 'documents',
            self::Knowledge => sprintf('workspaces/%d/knowledge', $workspaceId),
        };
    }

    /**
     * Max upload size in bytes.
     */
    public function maxBytes(): int
    {
        return match ($this) {
            self::Knowledge => 25 * 1024 * 1024,
            default => 20 * 1024 * 1024,
        };
    }

    /**
     * @return array<int, string> allowed mime types
     */
    public function allowedMimes(): array
    {
        return match ($this) {
            self::Knowledge => [
                'application/pdf',
                'text/plain',
                'text/markdown',
                'text/csv',
            ],
            default => [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ],
        };
    }
}
