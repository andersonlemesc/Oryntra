<?php

declare(strict_types=1);

return [
    /*
     | Public base URL that Chatwoot containers can call for webhooks.
     | In local Docker this is usually http://host.docker.internal:8080.
     */
    'webhook_base_url' => env('CHATWOOT_WEBHOOK_BASE_URL', env('APP_URL', 'http://localhost')),
];
