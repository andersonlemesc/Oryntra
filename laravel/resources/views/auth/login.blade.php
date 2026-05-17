<x-auth.layout title="Entrar | Oryntra" subtitle="Entre com sua conta para abrir o painel.">
    <form method="POST" action="{{ route('login.store') }}">
        @csrf

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>

        <label for="password">Senha</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <label class="check" for="remember">
            <input id="remember" name="remember" type="checkbox" value="1">
            Manter conectado
        </label>

        <div class="actions">
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}">Esqueci minha senha</a>
            @endif

            <button type="submit">Entrar</button>
        </div>
    </form>
</x-auth.layout>
