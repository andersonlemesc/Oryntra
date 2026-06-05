<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Enums\UploadPurpose;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RequestPresignedUpload
{
    /**
     * Reserve a storage path and return a presigned PUT URL plus a signed
     * upload_id that encodes the validated intent. The client PUTs the bytes
     * directly to MinIO, then calls confirm with the upload_id.
     *
     * @return array{upload_id:string, storage_path:string, put_url:string, headers:array<string,string>, expires_at:string}
     */
    public function execute(
        int $workspaceId,
        UploadPurpose $purpose,
        string $filename,
        string $mime,
        int $size,
        int $expiresMinutes = 15,
        string $disk = 's3',
    ): array {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin');
        $storagePath = sprintf('%s/%s.%s', $purpose->directory($workspaceId), Str::uuid()->toString(), $extension);

        $expiresAt = now()->addMinutes($expiresMinutes);
        /** @var array{url: string, headers: array<string, string>} $signed */
        $signed = Storage::disk($disk)->temporaryUploadUrl($storagePath, $expiresAt);

        $uploadId = Crypt::encryptString(json_encode([
            'workspace_id' => $workspaceId,
            'purpose' => $purpose->value,
            'storage_path' => $storagePath,
            'disk' => $disk,
            'original_filename' => $filename,
            'mime' => $mime,
            'size' => $size,
        ], JSON_THROW_ON_ERROR));

        return [
            'upload_id' => $uploadId,
            'storage_path' => $storagePath,
            'put_url' => $signed['url'],
            'headers' => $signed['headers'] ?? [],
            'expires_at' => (string) $expiresAt->toISOString(),
        ];
    }

    /**
     * Decode a signed upload_id back into its intent payload.
     *
     * @return array{workspace_id:int, purpose:string, storage_path:string, disk:string, original_filename:string, mime:string, size:int}
     */
    public function decode(string $uploadId): array
    {
        /** @var array{workspace_id:int, purpose:string, storage_path:string, disk:string, original_filename:string, mime:string, size:int} $payload */
        $payload = json_decode(Crypt::decryptString($uploadId), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
