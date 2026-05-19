<?php

declare(strict_types=1);

namespace App\Support\AgentTools;

class HandoffPrivateNoteRenderer
{
    public const DEFAULT_TEMPLATE = <<<'TEXT'
Handoff solicitado pela IA

Motivo: {reason}
Prioridade: {priority}
Especialista: {specialist_id}
Conversa: {conversation_id}

Mensagem ao cliente:
{customer_message}
TEXT;

    /**
     * @param array{reason?: string|null, priority?: string|null, specialist_id?: int|null, conversation_id?: int|null, customer_message?: string|null} $context
     */
    public function render(?string $template, array $context): string
    {
        $template = filled($template) ? (string) $template : self::DEFAULT_TEMPLATE;

        return strtr($template, [
            '{reason}' => (string) ($context['reason'] ?? ''),
            '{priority}' => (string) ($context['priority'] ?? ''),
            '{specialist_id}' => (string) ($context['specialist_id'] ?? ''),
            '{conversation_id}' => (string) ($context['conversation_id'] ?? ''),
            '{customer_message}' => (string) ($context['customer_message'] ?? ''),
        ]);
    }
}
