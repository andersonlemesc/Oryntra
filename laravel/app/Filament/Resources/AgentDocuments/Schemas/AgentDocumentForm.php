<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentDocuments\Schemas;

use App\Models\AgentLlmKey;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AgentDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('workspace_id')
                    ->default(fn (): ?int => Filament::getTenant()?->getKey()),
                Section::make('Documento')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TagsInput::make('tags')
                            ->label('Tags')
                            ->helperText('Use tags para filtrar a busca por assunto (opcional).'),
                        TextInput::make('description')
                            ->label('Descricao')
                            ->maxLength(1000),
                    ]),
                Section::make('Arquivo')
                    ->schema([
                        FileUpload::make('storage_path')
                            ->label('Arquivo')
                            ->disk('s3')
                            ->directory(fn (): string => 'workspaces/' . (Filament::getTenant()?->getKey() ?? 0) . '/knowledge')
                            ->acceptedFileTypes(['application/pdf', 'text/plain', 'text/markdown', 'text/csv'])
                            ->maxSize(25600)
                            ->required()
                            ->helperText('Prefira Markdown ou texto para melhor qualidade. PDFs sao processados por LLM (consome creditos do seu provedor BYOK).'),
                    ]),
                Section::make('Extracao de PDF (opcional)')
                    ->description('Usado apenas quando o PDF e escaneado ou complexo e o texto nao pode ser lido diretamente.')
                    ->schema([
                        Select::make('extractor_llm_key_id')
                            ->label('Chave LLM extratora')
                            ->options(fn (): array => self::visionKeyOptions())
                            ->searchable()
                            ->helperText('LLM com visao (OpenAI, Anthropic, Gemini) para transcrever PDFs escaneados.'),
                        TextInput::make('extractor_model')
                            ->label('Modelo extrator')
                            ->placeholder('ex.: gpt-4.1-mini')
                            ->maxLength(255),
                    ])
                    ->collapsed(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function visionKeyOptions(): array
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return [];
        }

        return AgentLlmKey::query()
            ->where('workspace_id', $tenant->getKey())
            ->get()
            ->mapWithKeys(fn (AgentLlmKey $key): array => [$key->getKey() => $key->name])
            ->all();
    }
}
