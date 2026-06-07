<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        $tagsIdx = $this->findColumnIndex($header, ['tags', 'etiquetas', 'sinonimos']);

        if ($nameIdx === null) {
            return ['imported' => 0, 'updated' => 0, 'errors' => ['Column "name" is required']];
        }

        DB::transaction(function () use ($lines, $workspaceId, $nameIdx, $skuIdx, $descIdx, $priceIdx, $categoryIdx, $tagsIdx, &$imported, &$updated, &$errors): void {
            foreach ($lines as $lineNum => $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $row = str_getcsv($line);
                if (count($row) <= $nameIdx) {
                    $errors[] = 'Line ' . ($lineNum + 2) . ': invalid row';

                    continue;
                }

                $name = trim($row[$nameIdx] ?? '');
                if ($name === '') {
                    $errors[] = 'Line ' . ($lineNum + 2) . ': name is empty';

                    continue;
                }

                $sku = $skuIdx !== null ? trim($row[$skuIdx] ?? '') : null;
                $description = $descIdx !== null ? trim($row[$descIdx] ?? '') : null;
                $price = $priceIdx !== null ? $this->parsePrice($row[$priceIdx] ?? '') : null;
                $category = $categoryIdx !== null ? trim($row[$categoryIdx] ?? '') : null;
                $categoryId = $category !== null && $category !== ''
                    ? $this->resolveCategoryId($workspaceId, $category)
                    : null;

                $tags = $tagsIdx !== null ? $this->parseTags($row[$tagsIdx] ?? '') : null;

                $data = [
                    'workspace_id' => $workspaceId,
                    'category_id' => $categoryId,
                    'name' => $name,
                    'description' => $description ?: null,
                    'price' => $price,
                    'tags' => $tags ?: null,
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

    /**
     * Split a tags cell on comma/semicolon/pipe into a clean list.
     *
     * @return array<int, string>
     */
    private function parseTags(string $value): array
    {
        $parts = preg_split('/[,;|]/', trim($value)) ?: [];

        return array_values(array_unique(array_filter(
            array_map('trim', $parts),
            fn (string $tag): bool => $tag !== '',
        )));
    }

    private function resolveCategoryId(int $workspaceId, string $name): int
    {
        $slug = Str::slug($name);

        $category = Category::query()->firstOrCreate(
            [
                'workspace_id' => $workspaceId,
                'slug' => $slug,
            ],
            [
                'name' => $name,
            ],
        );

        return $category->id;
    }
}
