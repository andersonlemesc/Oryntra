<?php

declare(strict_types=1);

namespace App\Jobs\Products;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use League\Csv\Reader;

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

    public function handle(): void
    {
        $disk = Storage::disk('s3');

        if (! $disk->exists($this->filePath)) {
            Log::error('ImportProductsJob: file not found', ['path' => $this->filePath]);
            return;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'products_import_');

        if ($temporaryPath === false) {
            Log::error('ImportProductsJob: failed to create temporary file', ['path' => $this->filePath]);
            return;
        }

        file_put_contents($temporaryPath, $disk->get($this->filePath));

        $csv = Reader::createFromPath($temporaryPath, 'r');
        $csv->setHeaderOffset(0);

        $records = $csv->getRecords();
        $count = 0;
        $errors = [];

        foreach ($records as $index => $record) {
            $rowNumber = $index + 2;

            $validator = Validator::make($record, [
                'name' => 'required|string|max:255',
                'sku' => 'nullable|string|max:255',
                'category' => 'nullable|string|max:255',
                'price' => 'nullable|numeric|min:0',
                'description' => 'nullable|string',
                'active' => 'nullable|in:0,1,true,false',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNumber}: " . implode(', ', $validator->errors()->all());
                continue;
            }

            $data = $validator->validated();
            $data['workspace_id'] = $this->workspaceId;
            $data['active'] = filter_var($data['active'] ?? '1', FILTER_VALIDATE_BOOLEAN);

            Product::create($data);
            $count++;
        }

        Log::info('ImportProductsJob: completed', [
            'workspace_id' => $this->workspaceId,
            'imported' => $count,
            'errors' => count($errors),
        ]);

        if ($errors !== []) {
            Log::warning('ImportProductsJob: some rows failed', ['errors' => $errors]);
        }

        unlink($temporaryPath);
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
