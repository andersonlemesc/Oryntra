<?php

declare(strict_types=1);

namespace App\Actions\AgentTools;

use App\Models\AgentRun;
use App\Models\ChatwootConnection;
use App\Models\Document;
use App\Models\ProductDocument;
use App\Services\Chatwoot\ChatwootAgentBotClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendDocument
{
    /**
     * @param  array{workspace_id:int,agent_run_id:int,document_id:int,caption:string,conversation_id:int} $payload
     * @return array{sent:bool,filename:string,error?:string}
     */
    public function execute(array $payload): array
    {
        $workspaceId = $payload['workspace_id'];
        $documentId = $payload['document_id'];
        $caption = $payload['caption'] ?? '';
        $conversationId = $payload['conversation_id'];

        $doc = ProductDocument::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $documentId)
            ->first();

        if ($doc === null) {
            $doc = Document::query()
                ->where('workspace_id', $workspaceId)
                ->where('id', $documentId)
                ->first();
        }

        if ($doc === null) {
            throw ValidationException::withMessages([
                'document_id' => 'Document not found or not in workspace.',
            ]);
        }

        $s3Disk = Storage::disk('s3');

        if (! $s3Disk->exists($doc->path)) {
            throw ValidationException::withMessages([
                'document_id' => 'File not found in storage.',
            ]);
        }

        $run = AgentRun::query()->find($payload['agent_run_id']);
        $connection = $run?->chatwootConnection;

        if ($connection === null) {
            throw ValidationException::withMessages([
                'agent_run_id' => 'Chatwoot connection not found for agent run.',
            ]);
        }

        $localPath = $this->downloadToLocalTemp($doc->path, $doc->filename);

        try {
            $client = new ChatwootAgentBotClient($connection);
            $client->sendConversationMessageWithAttachment(
                conversationId: $conversationId,
                content: $caption,
                filePath: $localPath,
                originalFilename: $doc->original_filename,
                mimeType: $doc->mime_type,
            );

            return [
                'sent' => true,
                'filename' => $doc->original_filename,
            ];
        } catch (Throwable $e) {
            Log::error('send_document.failed', [
                'workspace_id' => $workspaceId,
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'sent' => false,
                'filename' => $doc->original_filename,
                'error' => $e->getMessage(),
            ];
        } finally {
            if (file_exists($localPath)) {
                @unlink($localPath);
            }
        }
    }

    private function downloadToLocalTemp(string $s3Path, string $filename): string
    {
        $stream = Storage::disk('s3')->readStream($s3Path);
        $localPath = sys_get_temp_dir() . '/' . $filename;

        file_put_contents($localPath, stream_get_contents($stream));
        fclose($stream);

        return $localPath;
    }
}