<?php

declare(strict_types=1);

use App\Support\AgentTools\HandoffPrivateNoteRenderer;

it('renders the default private handoff note', function () {
    $note = (new HandoffPrivateNoteRenderer)->render(null, [
        'reason' => 'Cliente pediu cancelamento.',
        'priority' => 'high',
        'agent_name' => 'Vendas BikePulse',
        'recent_messages' => "- quero falar com humano\n- urgente",
        'conversation_summary' => 'Cliente insatisfeito quer cancelamento imediato.',
        'key_fact' => 'Pedido feito ontem ainda nao entregue.',
    ]);

    expect($note)->toContain('Handoff solicitado pela IA')
        ->and($note)->toContain('Agente: Vendas BikePulse')
        ->and($note)->toContain('Motivo: Cliente pediu cancelamento.')
        ->and($note)->toContain('Prioridade: high')
        ->and($note)->toContain('Resumo: Cliente insatisfeito quer cancelamento imediato.')
        ->and($note)->toContain('Fato relevante: Pedido feito ontem ainda nao entregue.')
        ->and($note)->toContain('quero falar com humano');
});

it('renders the default template without summary or key_fact when absent', function () {
    $note = (new HandoffPrivateNoteRenderer)->render(null, [
        'reason' => 'Cliente pediu cancelamento.',
        'priority' => 'high',
        'agent_name' => 'Vendas',
        'recent_messages' => '- urgente',
    ]);

    expect($note)->not->toContain('Resumo:')
        ->and($note)->not->toContain('Fato relevante:');
});

it('renders a custom private handoff note template', function () {
    $note = (new HandoffPrivateNoteRenderer)->render(
        'Motivo={reason}; Prioridade={priority}; Conversa={conversation_id}',
        [
            'reason' => 'Cliente pediu humano.',
            'priority' => 'urgent',
            'conversation_id' => 123,
        ],
    );

    expect($note)->toBe('Motivo=Cliente pediu humano.; Prioridade=urgent; Conversa=123');
});
