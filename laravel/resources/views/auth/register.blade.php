<x-auth.layout title="Criar conta | Oryntra" subtitle="Crie seu acesso inicial ao painel.">
    <form method="POST" action="{{ route('register.store') }}">
        @csrf

        <label for="name">Nome</label>
        <input id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" required autofocus>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>

        <label for="password">Senha</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required>

        <label for="password_confirmation">Confirmar senha</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>

        <div class="actions">
            <a href="{{ route('login') }}">Ja tenho conta</a>
            <button type="submit">Criar conta</button>
        </div>
    </form>
</x-auth.layout>
