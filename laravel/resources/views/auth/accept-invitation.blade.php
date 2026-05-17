<x-auth.layout title="Aceitar convite | Oryntra" subtitle="Defina sua senha para ativar sua conta.">
    <div style="margin-bottom:1rem">
        <p>Olá <strong>{{ $invitation->user->name }}</strong>!</p>
        <p>Confirme sua senha pra acessar Oryntra com o email <strong>{{ $invitation->email_sent_to }}</strong>.</p>
    </div>

    <form method="POST" action="{{ route('invitation.accept', ['token' => $invitation->token]) }}">
        @csrf

        <label for="password">Senha</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>

        <label for="password_confirmation">Confirmar senha</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>

        <div class="actions">
            <a href="{{ route('login') }}">Já tenho conta</a>
            <button type="submit">Ativar conta</button>
        </div>
    </form>
</x-auth.layout>
