<?php

declare(strict_types=1);

use App\Support\AgentTools\HandoffPrivateNoteRenderer;

it('renders the default private handoff note', function () {
    $note = (new HandoffPrivateNoteRenderer)->render(null, [
        'reason' => 'Cliente pediu cancelamento.',
        'priority' => 'high',
        'specialist_id' => 5,
        'conversation_id' => 99,
        'customer_message' => 'Vou transferir voce para um atendente.',
    ]);

    expect($note)->toContain('Handoff solicitado pela IA')
        ->and($note)->toContain('Motivo: Cliente pediu cancelamento.')
        ->and($note)->toContain('Prioridade: high')
        ->and($note)->toContain('Especialista: 5')
        ->and($note)->toContain('Conversa: 99')
        ->and($note)->toContain('Vou transferir voce para um atendente.');
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
