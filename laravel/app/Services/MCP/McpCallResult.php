<?php

declare(strict_types=1);

namespace App\Services\MCP;

final readonly class McpCallResult
{
    public function __construct(
        public string $text,
        public bool $isError,
        public ?string $error,
    ) {}

    public static function success(string $text): self
    {
        return new self(text: $text, isError: false, error: null);
    }

    public static function failure(string $error): self
    {
        return new self(text: "error: {$error}", isError: true, error: $error);
    }
}
