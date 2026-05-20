<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentRuns\Support;

use App\Actions\AgentRuns\ApproveAgentRun;
use App\Actions\AgentRuns\EditAgentRunResponse;
use App\Actions\AgentRuns\RejectAgentRun;
use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AgentRunHitlActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Aprovar')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Aprovar resposta da IA')
            ->modalDescription('A resposta sera marcada como concluida e o runtime sera notificado.')
            ->visible(fn (AgentRun $record): bool => $record->status === AgentRunStatus::WaitingHuman)
            ->action(function (AgentRun $record): void {
                try {
                    app(ApproveAgentRun::class)->execute($record, self::actorId());

                    Notification::make()
                        ->success()
                        ->title('Run aprovada')
                        ->send();
                } catch (ValidationException $exception) {
                    self::notifyValidation($exception);
                }
            });
    }

    public static function edit(): Action
    {
        return Action::make('editAndApprove')
            ->label('Editar e aprovar')
            ->icon('heroicon-o-pencil-square')
            ->color('info')
            ->visible(fn (AgentRun $record): bool => $record->status === AgentRunStatus::WaitingHuman
                && filled(data_get($record->output, 'response.content')))
            ->fillForm(fn (AgentRun $record): array => [
                'response_content' => (string) data_get($record->output, 'response.content'),
            ])
            ->schema([
                Textarea::make('response_content')
                    ->label('Resposta')
                    ->required()
                    ->rows(6)
                    ->columnSpanFull(),
            ])
            ->action(function (AgentRun $record, array $data): void {
                try {
                    app(EditAgentRunResponse::class)->execute(
                        $record,
                        self::actorId(),
                        (string) ($data['response_content'] ?? ''),
                    );

                    Notification::make()
                        ->success()
                        ->title('Resposta editada e aprovada')
                        ->send();
                } catch (ValidationException $exception) {
                    self::notifyValidation($exception);
                }
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Rejeitar')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (AgentRun $record): bool => $record->status === AgentRunStatus::WaitingHuman)
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(4)
                    ->columnSpanFull(),
            ])
            ->action(function (AgentRun $record, array $data): void {
                try {
                    app(RejectAgentRun::class)->execute(
                        $record,
                        self::actorId(),
                        (string) ($data['reason'] ?? ''),
                    );

                    Notification::make()
                        ->warning()
                        ->title('Run rejeitada')
                        ->send();
                } catch (ValidationException $exception) {
                    self::notifyValidation($exception);
                }
            });
    }

    private static function actorId(): ?int
    {
        $user = Auth::user();

        return $user === null ? null : (int) $user->getAuthIdentifier();
    }

    private static function notifyValidation(ValidationException $exception): void
    {
        $message = collect($exception->errors())->flatten()->first() ?? 'Acao invalida.';

        Notification::make()
            ->danger()
            ->title('Nao foi possivel concluir a acao')
            ->body((string) $message)
            ->send();
    }
}
