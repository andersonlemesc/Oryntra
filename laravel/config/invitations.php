<?php

declare(strict_types=1);

return [
    /*
     | TTL em horas pra invitations. Default 7 dias (168h).
     | Configurar via INVITATION_TTL_HOURS env.
     */
    'ttl_hours' => (int) env('INVITATION_TTL_HOURS', 168),

    /*
     | Path da página accept (rota com {token}).
     */
    'accept_path' => 'accept-invitation',

    /*
     | Send invite automaticamente quando user novo é criado via sync.
     | Default true. Desabilitar pra evitar spam em testes.
     */
    'send_on_sync' => filter_var(env('CHATWOOT_SYNC_SEND_INVITES', true), FILTER_VALIDATE_BOOLEAN),
];
