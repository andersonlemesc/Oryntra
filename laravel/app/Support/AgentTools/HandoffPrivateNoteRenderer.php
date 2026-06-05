<?php

declare(strict_types=1);

namespace App\Support\AgentTools;

class HandoffPrivateNoteRenderer
{
    public const DEFAULT_TEMPLATE = '__DEFAULT__';

    /**
     * @param array{reason?: string|null, priority?: string|null, specialist_id?: int|null, conversation_id?: int|null, customer_message?: string|null, agent_name?: string|null, recent_messages?: string|null, conversation_summary?: string|null, key_fact?: string|null} $context
     */
    public function render(?string $template, array $context): string
    {
        if (! filled($template)) {
            return $this->renderDefault($context);
        }

        return strtr((string) $template, [
            '{reason}' => (string) ($context['reason'] ?? ''),
            '{priority}' => (string) ($context['priority'] ?? ''),
            '{specialist_id}' => (string) ($context['specialist_id'] ?? ''),
            '{conversation_id}' => (string) ($context['conversation_id'] ?? ''),
            '{customer_message}' => (string) ($context['customer_message'] ?? ''),
            '{agent_name}' => (string) ($context['agent_name'] ?? ''),
            '{recent_messages}' => (string) ($context['recent_messages'] ?? ''),
            '{conversation_summary}' => (string) ($context['conversation_summary'] ?? ''),
            '{key_fact}' => (string) ($context['key_fact'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderDefault(array $context): string
    {
        $agentName = trim((string) ($context['agent_name'] ?? ''));
        $reason = trim((string) ($context['reason'] ?? ''));
        $priority = trim((string) ($context['priority'] ?? ''));
        $summary = trim((string) ($context['conversation_summary'] ?? ''));
        $keyFact = trim((string) ($context['key_fact'] ?? ''));
        $recent = trim((string) ($context['recent_messages'] ?? ''));

        $lines = ['Handoff solicitado pela IA', ''];

        if ($agentName !== '') {
            $lines[] = "Agente: {$agentName}";
        }
        if ($reason !== '') {
            $lines[] = "Motivo: {$reason}";
        }
        if ($priority !== '') {
            $lines[] = "Prioridade: {$priority}";
        }

        if ($summary !== '' || $keyFact !== '') {
            $lines[] = '';
            if ($summary !== '') {
                $lines[] = "Resumo: {$summary}";
            }
            if ($keyFact !== '') {
                $lines[] = "Fato relevante: {$keyFact}";
            }
        }

        if ($recent !== '') {
            $lines[] = '';
            $lines[] = 'Ultimas mensagens do cliente:';
            $lines[] = $recent;
        }

        return implode("\n", $lines);
    }
}
