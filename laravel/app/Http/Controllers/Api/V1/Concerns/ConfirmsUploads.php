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

        $this->assertStoredBytesMatch($payload, $expected);

        return $payload;
    }

    /**
     * Validate the bytes that actually landed in storage instead of trusting the
     * client-declared mime/size carried in the signed upload_id. The presigned
     * PUT does not enforce content-type or size, so a client could upload a file
     * that differs from what it requested (e.g. an executable disguised as a PDF,
     * which would later be served to customers).
     *
     * @param array{disk:string, storage_path:string} $payload
     *
     * @throws ValidationException
     */
    protected function assertStoredBytesMatch(array $payload, UploadPurpose $expected): void
    {
        $disk = Storage::disk($payload['disk']);
        $path = $payload['storage_path'];

        if ((int) $disk->size($path) > $expected->maxBytes()) {
            throw ValidationException::withMessages(['upload_id' => 'Arquivo enviado excede o tamanho máximo permitido.']);
        }

        $stream = $disk->readStream($path);
        $head = $stream !== null ? (string) fread($stream, 8192) : '';
        if ($stream !== null) {
            fclose($stream);
        }

        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head) ?: 'application/octet-stream';

        if (! $this->mimeIsAcceptable($sniffed, $expected->allowedMimes())) {
            throw ValidationException::withMessages([
                'upload_id' => "O conteúdo do arquivo ({$sniffed}) não corresponde a um tipo permitido para este recurso.",
            ]);
        }
    }

    /**
     * fileinfo reports text/markdown and text/csv as text/plain, so a sniffed
     * text/* type is accepted whenever the purpose allows any text/* mime.
     *
     * @param array<int, string> $allowed
     */
    private function mimeIsAcceptable(string $sniffed, array $allowed): bool
    {
        if (in_array($sniffed, $allowed, true)) {
            return true;
        }

        if (str_starts_with($sniffed, 'text/')) {
            foreach ($allowed as $mime) {
                if (str_starts_with($mime, 'text/')) {
                    return true;
                }
            }
        }

        return false;
    }
}
