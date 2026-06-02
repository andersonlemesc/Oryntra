<?php

declare(strict_types=1);

use App\Enums\UploadPurpose;
use App\Http\Controllers\Api\V1\Concerns\ConfirmsUploads;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Minimal harness exposing the protected byte-validation method.
 */
function uploadValidator(): object
{
    return new class
    {
        use ConfirmsUploads;

        /**
         * @param array{disk:string, storage_path:string} $payload
         */
        public function check(array $payload, UploadPurpose $purpose): void
        {
            $this->assertStoredBytesMatch($payload, $purpose);
        }
    };
}

function putFakeUpload(string $path, string $bytes): array
{
    Storage::fake('s3');
    Storage::disk('s3')->put($path, $bytes);

    return ['disk' => 's3', 'storage_path' => $path];
}

it('accepts a real PDF for the knowledge purpose', function () {
    $payload = putFakeUpload('workspaces/1/knowledge/a.pdf', "%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n<< >>\n%%EOF\n");

    uploadValidator()->check($payload, UploadPurpose::Knowledge);
})->throwsNoExceptions();

it('accepts plain text for the knowledge purpose (markdown/csv sniff as text/plain)', function () {
    $payload = putFakeUpload('workspaces/1/knowledge/notes.md', "# Título\n\nConteúdo de teste em texto puro.\n");

    uploadValidator()->check($payload, UploadPurpose::Knowledge);
})->throwsNoExceptions();

it('rejects an executable disguised as a document', function () {
    // ELF magic — fileinfo reports an executable mime, not an allowed one.
    $payload = putFakeUpload('documents/evil.pdf', "\x7fELF\x02\x01\x01\x00" . str_repeat("\x00", 64));

    expect(fn () => uploadValidator()->check($payload, UploadPurpose::Document))
        ->toThrow(ValidationException::class);
});

it('rejects an upload larger than the purpose maximum', function () {
    $payload = putFakeUpload('documents/big.pdf', '%PDF-1.4 ' . str_repeat('A', UploadPurpose::Document->maxBytes() + 1));

    expect(fn () => uploadValidator()->check($payload, UploadPurpose::Document))
        ->toThrow(ValidationException::class);
});
