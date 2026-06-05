<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Jobs\Products\ImportProductsJob;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('download_template')
                ->label('Baixar Modelo CSV')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(route('download.products-template')),
            Action::make('import_csv')
                ->label('Importar CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->form([
                    FileUpload::make('csv_file')
                        ->label('Arquivo CSV')
                        ->disk('s3')
                        ->directory('products/imports')
                        ->maxFiles(1)
                        ->maxSize(2048)
                        ->rules(['mimes:csv,txt'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $tenant = Filament::getTenant();

                    if ($tenant === null) {
                        return;
                    }

                    $filePath = $data['csv_file'];

                    if (is_array($filePath)) {
                        $filePath = $filePath[0] ?? '';
                    }

                    if (empty($filePath)) {
                        Notification::make()
                            ->title('Erro')
                            ->body('Arquivo não encontrado.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $extension = strtolower(pathinfo((string) $filePath, PATHINFO_EXTENSION));

                    if (! in_array($extension, ['csv', 'txt'], true)) {
                        Notification::make()
                            ->title('Erro')
                            ->body('Arquivo inválido. Use CSV.')
                            ->danger()
                            ->send();

                        return;
                    }

                    ImportProductsJob::dispatch(
                        $tenant->getKey(),
                        (string) $filePath,
                        auth()->id(),
                    );

                    Notification::make()
                        ->title('Importação iniciada')
                        ->body('Os produtos estão sendo importados em segundo plano.')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getTableQuery(): Builder
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return parent::getTableQuery();
        }

        return parent::getTableQuery()->where('workspace_id', $tenant->getKey());
    }
}
