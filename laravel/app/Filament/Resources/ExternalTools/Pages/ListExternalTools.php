<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExternalTools\Pages;

use App\Filament\Resources\ExternalTools\ExternalToolResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExternalTools extends ListRecords
{
    protected static string $resource = ExternalToolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
