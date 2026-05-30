<?php

declare(strict_types=1);

namespace App\Services\MCP;

final readonly class McpSession
{
    public function __construct(
        public string $serverSlug,
        public ?string $sessionId,
    ) {}
}
