<?php

declare(strict_types=1);

namespace App\Filament\Resources\McpServers\Support;

use App\Models\ExternalTool;
use App\Services\MCP\McpHttpClient;
use Throwable;

/**
 * Calls initialize + listTools on an MCP server and renders a tool list
 * for display inside a Filament modal.
 */
final class McpToolsListContent
{
    public static function buildHtml(ExternalTool $server): string
    {
        $client = app(McpHttpClient::class);

        try {
            $session = $client->initialize($server);
            $tools = $client->listTools($server, $session);
        } catch (Throwable $e) {
            return self::errorHtml('Não foi possível conectar ao servidor: ' . e($e->getMessage()));
        }

        if (empty($tools)) {
            return '<p class="text-sm text-gray-500 dark:text-gray-400 py-2">O servidor não retornou nenhuma tool.</p>';
        }

        $cards = '';
        foreach ($tools as $tool) {
            if (! is_array($tool)) {
                continue;
            }

            $name = e((string) ($tool['name'] ?? '—'));
            $description = e((string) ($tool['description'] ?? '—'));
            $properties = is_array($tool['inputSchema']['properties'] ?? null)
                ? $tool['inputSchema']['properties']
                : [];
            $required = is_array($tool['inputSchema']['required'] ?? null)
                ? $tool['inputSchema']['required']
                : [];

            $paramBadges = '';
            foreach ($properties as $paramName => $def) {
                $paramName = e((string) $paramName);
                $type = e((string) ($def['type'] ?? 'string'));
                $isRequired = in_array($paramName, $required, true);
                $requiredBadge = $isRequired
                    ? '<span class="ml-1 text-[10px] font-medium text-danger-600 dark:text-danger-400">obrig.</span>'
                    : '';

                $paramBadges .= <<<HTML
                    <span class="inline-flex items-center gap-0.5 rounded bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 text-xs font-mono text-gray-700 dark:text-gray-300">
                        {$paramName}<span class="text-gray-400 dark:text-gray-500">:{$type}</span>{$requiredBadge}
                    </span>
                HTML;
            }

            $paramsSection = $paramBadges !== ''
                ? "<div class=\"mt-2 flex flex-wrap gap-1.5\">{$paramBadges}</div>"
                : '<p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">Sem parâmetros declarados.</p>';

            $cards .= <<<HTML
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 p-3">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-sm font-semibold text-primary-600 dark:text-primary-400">{$name}</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{$description}</p>
                    {$paramsSection}
                </div>
            HTML;
        }

        $count = count($tools);
        $label = $count === 1 ? '1 tool descoberta' : "{$count} tools descobertas";

        return <<<HTML
            <div class="space-y-2">
                <p class="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide mb-3">{$label}</p>
                {$cards}
            </div>
        HTML;
    }

    private static function errorHtml(string $message): string
    {
        return <<<HTML
            <div class="flex items-start gap-2 rounded-lg border border-danger-200 dark:border-danger-800 bg-danger-50 dark:bg-danger-900/20 p-3 text-danger-700 dark:text-danger-400">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
                <p class="text-sm">{$message}</p>
            </div>
        HTML;
    }
}
