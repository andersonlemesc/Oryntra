<?php

declare(strict_types=1);

namespace App\Filament\Resources\McpServers\Pages;

use App\Filament\Resources\McpServers\McpServerResource;
use App\Filament\Resources\McpServers\Support\McpServerFormState;
use App\Filament\Resources\McpServers\Support\McpToolsListContent;
use App\Models\ExternalTool;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;

class EditMcpServer extends EditRecord
{
    protected static string $resource = McpServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('listTools')
                ->label('Ver tools')
                ->icon('heroicon-o-list-bullet')
                ->color('info')
                ->modalHeading('Tools disponíveis')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->modalContent(function (): HtmlString {
                    /** @var ExternalTool $record */
                    $record = $this->getRecord();

                    return new HtmlString(McpToolsListContent::buildHtml($record));
                }),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return McpServerFormState::hydrate($data);
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var ExternalTool $record */
        $record = $this->record;

        return McpServerFormState::assemble($data, $record);
    }
}
