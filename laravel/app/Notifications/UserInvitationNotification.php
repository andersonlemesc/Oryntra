<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly UserInvitation $invitation)
    {
        $this->afterCommit();
        $this->onQueue('emails');
    }

    /**
     * @return array<int, string>
     */
    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url((string) config('invitations.accept_path') . '/' . $this->invitation->token);
        $appName = (string) config('app.name', 'Oryntra');
        $userName = $this->invitation->user->name ?? 'usuário';

        return (new MailMessage)
            ->subject("Convite para acessar {$appName}")
            ->greeting("Olá {$userName},")
            ->line("Você foi convidado a acessar a plataforma {$appName}.")
            ->line('Clique no botão abaixo para definir sua senha e ativar sua conta.')
            ->action('Aceitar convite e definir senha', $url)
            ->line('Este convite expira em ' . $this->invitation->expires_at->diffForHumans() . '.')
            ->line('Se você não esperava este convite, ignore este email.');
    }
}
