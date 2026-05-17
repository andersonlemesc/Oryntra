<x-auth.layout title="Setup inicial | Oryntra" subtitle="Configure a conexao Chatwoot Platform para sincronizar workspaces e usuarios.">
    @if ($lastError && ! $errors->any())
        <div class="errors">
            <strong>Ultimo erro de sincronizacao:</strong>
            <p>{{ $lastError }}</p>
        </div>
    @endif

    <form method="POST" action="{{ route('setup.platform.store') }}">
        @csrf

        <label for="base_url">URL base do Chatwoot</label>
        <input
            id="base_url"
            name="base_url"
            type="url"
            value="{{ $baseUrl }}"
            placeholder="http://host.docker.internal:3000"
            required
            autofocus
        >

        <label for="platform_token">Platform App Token</label>
        <input
            id="platform_token"
            name="platform_token"
            type="password"
            autocomplete="off"
            required
        >

        <div class="actions">
            <a href="{{ route('login') }}">Sair</a>
            <button type="submit">Configurar e sincronizar</button>
        </div>
    </form>
</x-auth.layout>
