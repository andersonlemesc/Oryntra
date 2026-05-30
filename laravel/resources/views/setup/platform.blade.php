<x-auth.layout title="Setup inicial | Oryntra" subtitle="Configure a conexao Chatwoot Platform para sincronizar workspaces e usuarios.">
    <div class="info">
        <strong>Como obter o Platform App Token</strong>
        <ol>
            <li>Acesse o painel super admin do seu Chatwoot: <code>{URL do Chatwoot}/super_admin/platform_apps</code></li>
            <li>Clique em <strong>New Platform App</strong>, dê um nome (ex: <em>Oryntra</em>) e salve.</li>
            <li>Copie o token gerado e cole no campo abaixo.</li>
        </ol>
        <p>Este token é <strong>global</strong> — ele permite ao Oryntra importar todas as accounts e usuários da instância Chatwoot. Não está vinculado a nenhum workspace específico.</p>
    </div>

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
