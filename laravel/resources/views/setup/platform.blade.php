<x-auth.layout title="Setup inicial | Oryntra" subtitle="Configure a conexao Chatwoot Platform para sincronizar workspaces e usuarios.">
    <div class="info">
        <strong>Como obter o Platform App Token</strong>
        <ol>
            <li>Acesse o painel super admin do seu Chatwoot: <code>{URL do Chatwoot}/super_admin/platform_apps</code></li>
            <li>Clique em <strong>New Platform App</strong>, dê um nome (ex: <em>Oryntra</em>) e salve.</li>
            <li>Copie o token gerado e cole no campo abaixo.</li>
        </ol>
        <p>Este token é de nível plataforma — o Oryntra o usa para importar accounts e usuários do Chatwoot. Não está vinculado a nenhum workspace específico.</p>
    </div>

    <div class="info">
        <strong>Importar contas que já existiam no Chatwoot</strong>
        <p>
            Por padrão, o Chatwoot só expõe ao token as accounts <strong>criadas por este Platform App</strong>.
            Accounts que já existiam (ou criadas pelo cadastro do próprio Chatwoot) ficam <strong>invisíveis</strong> ao token —
            por isso o setup não as encontra. Rode o comando abaixo <strong>uma vez</strong> no servidor do Chatwoot para
            autorizar o Platform App a ler todas as accounts e usuários; depois o Oryntra sincroniza normalmente.
        </p>

        <p>Via Docker, no host do Chatwoot (troque <code>CONTAINER_CHATWOOT</code> pelo nome do container):</p>
        <pre><code class="select-all">docker exec -it CONTAINER_CHATWOOT bundle exec rails runner 'app = PlatformApp.first; Account.find_each { |a| PlatformAppPermissible.find_or_create_by!(platform_app: app, permissible: a) }; User.find_each { |u| PlatformAppPermissible.find_or_create_by!(platform_app: app, permissible: u) }; puts "ok: #{Account.count} accounts, #{User.count} users"'</code></pre>

        <p>Ou direto dentro do container do Chatwoot (já no shell):</p>
        <pre><code class="select-all">bundle exec rails runner 'app = PlatformApp.first; Account.find_each { |a| PlatformAppPermissible.find_or_create_by!(platform_app: app, permissible: a) }; User.find_each { |u| PlatformAppPermissible.find_or_create_by!(platform_app: app, permissible: u) }; puts "ok: #{Account.count} accounts, #{User.count} users"'</code></pre>

        <p>É idempotente (pode rodar de novo sem duplicar) e só cria os vínculos de leitura — não altera suas accounts.</p>
    </div>

    @if ($errors->any())
        <div class="errors">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

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
