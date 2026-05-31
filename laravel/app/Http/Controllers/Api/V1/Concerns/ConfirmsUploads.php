<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Actions\Api\RequestPresignedUpload;
use App\Enums\UploadPurpose;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Shared logic for confirming a presigned upload: decode the signed upload_id,
 * verify it targets the active workspace and expected purpose, and that the
 * object actually landed in storage.
 */
trait ConfirmsUploads
{
    /**
     * @return array{workspace_id:int, purpose:string, storage_path:string, disk:string, original_filename:string, mime:string, size:int}
     *
     * @throws ValidationException
     */
    protected function resolveConfirmedUpload(string $uploadId, UploadPurpose $expected, int $workspaceId): array
    {
        try {
            $payload = app(RequestPresignedUpload::class)->decode($uploadId);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['upload_id' => 'upload_id inválido ou expirado.']);
        }

        if ((int) $payload['workspace_id'] !== $workspaceId) {
            throw ValidationException::withMessages(['upload_id' => 'upload_id pertence a outro workspace.']);
        }

        if ($payload['purpose'] !== $expected->value) {
            throw ValidationException::withMessages(['upload_id' => 'upload_id não corresponde a este tipo de recurso.']);
        }

        if (! Storage::disk($payload['disk'])->exists($payload['storage_path'])) {
            throw ValidationException::withMessages(['upload_id' => 'Arquivo não encontrado no storage. Faça o PUT antes de confirmar.']);
        }

        return $payload;
    }
}
