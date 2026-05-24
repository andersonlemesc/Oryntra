<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('create');
    }

    protected function hasCreateAnotherButton(): bool
    {
        return false;
    }
}