<x-auth.layout title="Verificacao em duas etapas | Oryntra" subtitle="Use o codigo do autenticador ou um codigo de recuperacao.">
    <form method="POST" action="{{ route('two-factor.login.store') }}">
        @csrf

        <label for="code">Codigo do autenticador</label>
        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" autofocus>

        <label for="recovery_code">Codigo de recuperacao</label>
        <input id="recovery_code" name="recovery_code" type="text" autocomplete="one-time-code">

        <div class="actions">
            <a href="{{ route('login') }}">Voltar ao login</a>
            <button type="submit">Verificar</button>
        </div>
    </form>
</x-auth.layout>
