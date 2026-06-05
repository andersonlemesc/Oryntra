<?php

declare(strict_types=1);

namespace App\Jobs\Products;

use App\Actions\Products\ImportProductsFromCsv;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportProductsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $filePath,
        public readonly int $userId,
    ) {}

    public function handle(ImportProductsFromCsv $importProducts): void
    {
        $disk = Storage::disk('s3');

        if (! $disk->exists($this->filePath)) {
            Log::error('ImportProductsJob: file not found', ['path' => $this->filePath]);

            return;
        }

        $csvContent = $disk->get($this->filePath);
        $result = $importProducts->execute($this->workspaceId, $csvContent);

        Log::info('ImportProductsJob: completed', [
            'workspace_id' => $this->workspaceId,
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'errors' => count($result['errors']),
        ]);

        if ($result['errors'] !== []) {
            Log::warning('ImportProductsJob: some rows failed', ['errors' => $result['errors']]);
        }

        $disk->delete($this->filePath);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ImportProductsJob: failed', [
            'workspace_id' => $this->workspaceId,
            'exception' => $exception->getMessage(),
        ]);

        Storage::disk('s3')->delete($this->filePath);
    }
}
