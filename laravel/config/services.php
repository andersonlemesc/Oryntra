<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'agent_runtime' => [
        'base_url' => env('AGENT_RUNTIME_URL', env('AGENT_PYTHON_URL', 'http://agent-python:8000')),
        'internal_token' => env('AGENT_RUNTIME_INTERNAL_TOKEN', env('INTERNAL_API_TOKEN')),
        'timeout' => (int) env('AGENT_RUNTIME_TIMEOUT', 30),
    ],

    'chatwoot' => [
        'internal_base_url' => env('CHATWOOT_PLATFORM_BASE_URL'),
    ],

];
