<x-auth.layout title="Recuperar senha | Oryntra" subtitle="Informe seu email para receber o link de redefinicao.">
    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>

        <div class="actions">
            <a href="{{ route('login') }}">Voltar ao login</a>
            <button type="submit">Enviar link</button>
        </div>
    </form>
</x-auth.layout>
