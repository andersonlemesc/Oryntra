<?php

declare(strict_types=1);

use App\Services\AgentTools\NativeTool;
use App\Services\AgentTools\NativeToolRegistry;
use Tests\TestCase;

uses(TestCase::class);

it('lists native Chatwoot tools available to specialists', function () {
    $registry = new NativeToolRegistry;

    expect($registry->options())->toMatchArray([
        NativeTool::RequestHumanHandoff->value => 'Transferir para humano',
        NativeTool::ChatwootSendMessage->value => 'Enviar mensagem Chatwoot',
        NativeTool::ChatwootAddPrivateNote->value => 'Adicionar nota interna',
        NativeTool::ChatwootAddLabel->value => 'Adicionar label',
        NativeTool::ChatwootAssignTeam->value => 'Atribuir time',
        NativeTool::ChatwootAssignAgent->value => 'Atribuir atendente',
        NativeTool::ResolveConversation->value => 'Encerrar conversa',
        NativeTool::QueryProducts->value => 'Consultar produtos',
        NativeTool::SendDocument->value => 'Enviar documento',
    ]);
});
