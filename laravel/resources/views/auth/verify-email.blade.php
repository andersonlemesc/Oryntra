<x-auth.layout title="Verificar email | Oryntra" subtitle="Confirme seu email antes de continuar.">
    <form method="POST" action="{{ route('verification.send') }}">
        @csrf

        <p class="lede">Se o email nao chegou, solicite um novo link de verificacao.</p>

        <div class="actions">
            <a href="{{ url('/admin') }}">Voltar ao painel</a>
            <button type="submit">Reenviar email</button>
        </div>
    </form>
</x-auth.layout>
