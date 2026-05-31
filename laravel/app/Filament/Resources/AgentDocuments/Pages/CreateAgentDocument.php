<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentDocuments\Pages;

use App\Enums\AgentDocumentStatus;
use App\Filament\Resources\AgentDocuments\AgentDocumentResource;
use App\Jobs\Rag\IndexKnowledgeDocumentJob;
use App\Models\AgentDocument;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateAgentDocument extends CreateRecord
{
    protected static string $resource = AgentDocumentResource::class;

    private const MIME_MAP = [
        'pdf' => 'application/pdf',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
    ];

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $path = is_string($data['storage_path'] ?? null) ? $data['storage_path'] : '';
        $disk = Storage::disk('s3');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $exists = $path !== '' && $disk->exists($path);
        $contents = $exists ? (string) $disk->get($path) : '';

        $data['storage_disk'] = 's3';
        $data['mime_type'] = self::MIME_MAP[$extension] ?? 'application/octet-stream';
        $data['size_bytes'] = $exists ? (int) $disk->size($path) : 0;
        $data['checksum'] = $contents !== '' ? hash('sha256', $contents) : null;
        $data['index_status'] = AgentDocumentStatus::Pending->value;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var AgentDocument $document */
        $document = $this->record;

        IndexKnowledgeDocumentJob::dispatch($document->id);
    }
}
