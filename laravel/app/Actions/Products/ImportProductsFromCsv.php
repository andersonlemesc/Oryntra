<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ImportProductsFromCsv
{
    private const REQUIRED_HEADERS = ['name'];

    /**
     * @return array{imported: int, updated: int, errors: array<int, string>}
     */
    public function execute(int $workspaceId, string $csvContent): array
    {
        $errors = [];
        $imported = 0;
        $updated = 0;

        $lines = explode("\n", trim($csvContent));
        if (count($lines) < 2) {
            return ['imported' => 0, 'updated' => 0, 'errors' => ['CSV must have header and at least one data row']];
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map('trim', $header);
        $header = array_map('mb_strtolower', $header);

        $nameIdx = $this->findColumnIndex($header, ['name', 'titulo', 'produto']);
        $skuIdx = $this->findColumnIndex($header, ['sku', 'codigo', 'cod']);
        $descIdx = $this->findColumnIndex($header, ['description', 'descricao', 'desc']);
        $priceIdx = $this->findColumnIndex($header, ['price', 'preco', 'valor']);
        $categoryIdx = $this->findColumnIndex($header, ['category', 'categoria', 'cat']);

        if ($nameIdx === null) {
            return ['imported' => 0, 'updated' => 0, 'errors' => ['Column "name" is required']];
        }

        DB::transaction(function () use ($lines, $workspaceId, $header, $nameIdx, $skuIdx, $descIdx, $priceIdx, $categoryIdx, &$imported, &$updated, &$errors): void {
            foreach ($lines as $lineNum => $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $row = str_getcsv($line);
                if (count($row) <= $nameIdx) {
                    $errors[] = "Line " . ($lineNum + 2) . ": invalid row";
                    continue;
                }

                $name = trim($row[$nameIdx] ?? '');
                if ($name === '') {
                    $errors[] = "Line " . ($lineNum + 2) . ": name is empty";
                    continue;
                }

                $sku = $skuIdx !== null ? trim($row[$skuIdx] ?? '') : null;
                $description = $descIdx !== null ? trim($row[$descIdx] ?? '') : null;
                $price = $priceIdx !== null ? $this->parsePrice($row[$priceIdx] ?? '') : null;
                $category = $categoryIdx !== null ? trim($row[$categoryIdx] ?? '') : null;

                $data = [
                    'workspace_id' => $workspaceId,
                    'name' => $name,
                    'description' => $description ?: null,
                    'price' => $price,
                    'category' => $category ?: null,
                ];

                if ($sku !== null && $sku !== '') {
                    $existing = Product::query()
                        ->where('workspace_id', $workspaceId)
                        ->where('sku', $sku)
                        ->first();

                    if ($existing !== null) {
                        $existing->update(array_filter($data, fn ($v) => $v !== null));
                        $updated++;
                    } else {
                        $data['sku'] = $sku;
                        Product::create($data);
                        $imported++;
                    }
                } else {
                    Product::create($data);
                    $imported++;
                }
            }
        });

        return ['imported' => $imported, 'updated' => $updated, 'errors' => $errors];
    }

    private function findColumnIndex(array $header, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $idx = array_search($candidate, $header, true);
            if ($idx !== false) {
                return $idx;
            }
        }

        return null;
    }

    private function parsePrice(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = str_replace(['R$', ' ', ','], ['', '', '.'], $value);
        $value = preg_replace('/[^\d.]/', '', $value);

        if ($value === '' || $value === '.') {
            return null;
        }

        return (float) $value;
    }
}