<x-auth.layout title="Confirmar senha | Oryntra" subtitle="Confirme sua senha para continuar.">
    <form method="POST" action="{{ route('password.confirm.store') }}">
        @csrf

        <label for="password">Senha</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>

        <div class="actions">
            <a href="{{ url('/admin') }}">Voltar ao painel</a>
            <button type="submit">Confirmar</button>
        </div>
    </form>
</x-auth.layout>
