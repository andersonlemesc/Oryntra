<x-auth.layout title="Nova senha | Oryntra" subtitle="Defina uma nova senha para sua conta.">
    <form method="POST" action="{{ route('password.update') }}">
        @csrf

        <input name="token" type="hidden" value="{{ request()->route('token') }}">

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', request('email')) }}" autocomplete="email" required autofocus>

        <label for="password">Nova senha</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required>

        <label for="password_confirmation">Confirmar senha</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>

        <div class="actions">
            <a href="{{ route('login') }}">Voltar ao login</a>
            <button type="submit">Salvar senha</button>
        </div>
    </form>
</x-auth.layout>
