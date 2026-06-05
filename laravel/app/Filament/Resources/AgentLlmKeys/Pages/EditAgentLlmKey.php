<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentLlmKeys\Pages;

use App\Filament\Resources\AgentLlmKeys\AgentLlmKeyResource;
use App\Models\AgentLlmKey;
use App\Services\Llm\LlmModelCatalog;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditAgentLlmKey extends EditRecord
{
    protected static string $resource = AgentLlmKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncModels')
                ->label('Sincronizar modelos')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->action(function (LlmModelCatalog $catalog): void {
                    /** @var AgentLlmKey $record */
                    $record = $this->getRecord();

                    try {
                        $count = $catalog->sync($record);
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Falha ao sincronizar modelos')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("{$count} modelos sincronizados")
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
