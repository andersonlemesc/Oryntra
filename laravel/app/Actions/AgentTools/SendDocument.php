<?php

declare(strict_types=1);

namespace App\Actions\AgentTools;

use App\Enums\AgentRunSource;
use App\Models\AgentRun;
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
     * Sends one or more documents to the customer as a single Chatwoot message
     * with multiple attachments. All ids must share the same document_type.
     *
     * @param  array{workspace_id:int,agent_run_id:int,document_ids:array<int,int>,document_type:string,caption?:string,conversation_id:int} $payload
     * @return array{sent:bool,filenames:array<int,string>,count:int,error?:string}
     */
    public function execute(array $payload): array
    {
        $workspaceId = $payload['workspace_id'];
        $documentIds = array_values(array_unique(array_map('intval', $payload['document_ids'])));
        $documentType = $payload['document_type'];
        $caption = $payload['caption'] ?? '';
        $conversationId = $payload['conversation_id'];

        if ($documentIds === []) {
            throw ValidationException::withMessages([
                'document_ids' => 'At least one document is required.',
            ]);
        }

        $s3Disk = Storage::disk('s3');
        $docs = [];

        foreach ($documentIds as $documentId) {
            $doc = $this->resolveDocument($workspaceId, $documentId, $documentType);

            if ($doc === null) {
                throw ValidationException::withMessages([
                    'document_ids' => "Document {$documentId} not found or not in workspace.",
                ]);
            }

            if ($doc instanceof Document && ! $doc->isSendable()) {
                throw ValidationException::withMessages([
                    'document_ids' => "Document {$documentId} category is AI-knowledge-only and cannot be sent to the customer.",
                ]);
            }

            if (! $s3Disk->exists($doc->path)) {
                throw ValidationException::withMessages([
                    'document_ids' => "File for document {$documentId} not found in storage.",
                ]);
            }

            $docs[] = $doc;
        }

        $run = AgentRun::query()->find($payload['agent_run_id']);
        $connection = $run?->chatwootConnection;

        if ($connection === null) {
            // Playground runs have no Chatwoot connection. Simulate a successful
            // delivery (documents already validated above) so the sales flow can
            // be tested end-to-end without actually sending anything.
            if ($run instanceof AgentRun && $run->source === AgentRunSource::Playground) {
                /** @var array<int, string> $simulatedFilenames */
                $simulatedFilenames = array_map(
                    fn (Document|ProductDocument $doc): string => $doc->original_filename,
                    $docs,
                );

                return [
                    'sent' => true,
                    'filenames' => $simulatedFilenames,
                    'count' => count($simulatedFilenames),
                ];
            }

            throw ValidationException::withMessages([
                'agent_run_id' => 'Chatwoot connection not found for agent run.',
            ]);
        }

        /** @var array<int, string> $filenames */
        $filenames = array_map(fn (Document|ProductDocument $doc): string => $doc->original_filename, $docs);

        $localPaths = [];
        $attachments = [];

        foreach ($docs as $doc) {
            $localPath = $this->downloadToLocalTemp($doc->path, $doc->filename);
            $localPaths[] = $localPath;
            $attachments[] = [
                'path' => $localPath,
                'filename' => $doc->original_filename,
                'mime' => $doc->mime_type,
            ];
        }

        try {
            $client = new ChatwootAgentBotClient($connection);

            // With multiple attachments the WhatsApp bridge repeats the message
            // content as a caption on every image. Send the caption once as text,
            // then deliver the gallery without a per-image caption.
            $mediaCaption = $caption;
            if (count($attachments) > 1 && $caption !== '') {
                $client->sendConversationMessage($conversationId, $caption);
                $mediaCaption = '';
            }

            $client->sendConversationMessageWithAttachments(
                conversationId: $conversationId,
                content: $mediaCaption,
                attachments: $attachments,
            );

            return [
                'sent' => true,
                'filenames' => $filenames,
                'count' => count($filenames),
            ];
        } catch (Throwable $e) {
            Log::error('send_document.failed', [
                'workspace_id' => $workspaceId,
                'document_ids' => $documentIds,
                'error' => $e->getMessage(),
            ]);

            return [
                'sent' => false,
                'filenames' => $filenames,
                'count' => count($filenames),
                'error' => $e->getMessage(),
            ];
        } finally {
            foreach ($localPaths as $localPath) {
                if (file_exists($localPath)) {
                    @unlink($localPath);
                }
            }
        }
    }

    private function resolveDocument(int $workspaceId, int $documentId, string $documentType): Document|ProductDocument|null
    {
        if ($documentType === 'product') {
            return ProductDocument::query()
                ->where('workspace_id', $workspaceId)
                ->where('id', $documentId)
                ->first();
        }

        return Document::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $documentId)
            ->first();
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
