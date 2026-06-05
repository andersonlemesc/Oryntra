<?php

declare(strict_types=1);

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadTemplateController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            echo "name,sku,description,price,category\n";
            echo "Bike Eletrica Urbana,BIKE-001,Autonomia de 50km,3499.90,Bikes\n";
        }, 'produtos_template.csv', [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment',
        ]);
    }
}
