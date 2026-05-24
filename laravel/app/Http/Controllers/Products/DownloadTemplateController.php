<?php

declare(strict_types=1);

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadTemplateController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $templatePath = storage_path('app/products/template.csv');

        if (! file_exists($templatePath)) {
            abort(404, 'Template não encontrado');
        }

        return response()->download($templatePath, 'produtos_template.csv', [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment',
        ]);
    }
}