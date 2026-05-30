<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\AgentLlmModel;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Builds a "pick a synced model or type a custom one" field pair for an LLM
 * model column. The choice Select is populated from the models synced on the
 * selected key; choosing "Custom…" reveals a free TextInput.
 *
 * Persistence is resolved in the form's mutate hooks via {@see self::resolve()}:
 * the choice Select dehydrates into a transient `<column>__choice` key which is
 * collapsed back onto the real column (or replaced by the custom TextInput
 * value). This keeps it robust to programmatic fills, where afterStateUpdated
 * does not fire.
 */
final class LlmModelField
{
    public const CUSTOM = '__custom__';

    private const SUFFIX = '__choice';

    /**
     * @param  bool|Closure|null            $visible extra gate ANDed with the field's own visibility
     * @return array<int, Select|TextInput>
     */
    public static function components(
        string $modelColumn,
        string $keyField,
        bool|Closure $required = false,
        string $label = 'Modelo',
        bool|Closure|null $visible = null,
    ): array {
        $choiceField = $modelColumn . self::SUFFIX;

        $select = Select::make($choiceField)
            ->label($label)
            ->live()
            ->required(fn (Get $get): bool => self::resolveGate($required, $get) && self::resolveGate($visible ?? true, $get))
            ->options(fn (Get $get): array => self::options($get($keyField)))
            ->searchable()
            ->optionsLimit(200)
            ->afterStateHydrated(function (Get $get, Set $set) use ($modelColumn, $keyField, $choiceField): void {
                $current = $get($modelColumn);

                if (blank($current)) {
                    $set($choiceField, null);

                    return;
                }

                $options = self::options($get($keyField));
                $set($choiceField, array_key_exists($current, $options) ? $current : self::CUSTOM);
            })
            ->helperText('Sincronize a chave para listar modelos. Use "Custom..." para digitar manualmente.');

        if ($visible !== null) {
            $select->visible($visible);
        }

        $textInput = TextInput::make($modelColumn)
            ->label($label . ' (custom)')
            ->maxLength(128)
            ->visible(fn (Get $get): bool => $get($choiceField) === self::CUSTOM && self::resolveGate($visible ?? true, $get))
            ->required(fn (Get $get): bool => $get($choiceField) === self::CUSTOM
                && self::resolveGate($required, $get) && self::resolveGate($visible ?? true, $get));

        return [$select, $textInput];
    }

    /**
     * Collapse the transient `<column>__choice` keys onto their real columns.
     * Call from mutateFormDataBeforeCreate/Save (and the relation-manager
     * equivalents) for every model column built via {@see self::components()}.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function resolve(array $data, string ...$modelColumns): array
    {
        foreach ($modelColumns as $modelColumn) {
            $choiceField = $modelColumn . self::SUFFIX;

            if (! array_key_exists($choiceField, $data)) {
                continue;
            }

            $choice = $data[$choiceField];
            unset($data[$choiceField]);

            if ($choice !== self::CUSTOM && filled($choice)) {
                $data[$modelColumn] = $choice;
            }
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private static function options(mixed $keyId): array
    {
        if (blank($keyId)) {
            return [self::CUSTOM => 'Custom...'];
        }

        $models = AgentLlmModel::query()
            ->where('agent_llm_key_id', $keyId)
            ->orderBy('model_id')
            ->pluck('label', 'model_id')
            ->all();

        return $models + [self::CUSTOM => 'Custom...'];
    }

    private static function resolveGate(bool|Closure $gate, Get $get): bool
    {
        return $gate instanceof Closure
            ? (bool) $gate($get)
            : $gate;
    }
}
