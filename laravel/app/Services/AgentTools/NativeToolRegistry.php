<?php

declare(strict_types=1);

namespace App\Services\AgentTools;

final class NativeToolRegistry
{
    /**
     * @return array<string, array{label: string, description: string}>
     */
    public function tools(): array
    {
        return [
            NativeTool::RequestHumanHandoff->value => [
                'label' => 'Transferir para humano',
                'description' => 'Encerra a IA e dispara handoff para atendente humano.',
            ],
            NativeTool::RequestTeamHandoff->value => [
                'label' => 'Transferir para time',
                'description' => 'Encerra a IA e dispara handoff para um time Chatwoot.',
            ],
            NativeTool::ChatwootSendMessage->value => [
                'label' => 'Enviar mensagem Chatwoot',
                'description' => 'Envia uma mensagem publica ao cliente.',
            ],
            NativeTool::ChatwootAddPrivateNote->value => [
                'label' => 'Adicionar nota interna',
                'description' => 'Adiciona mensagem privada para o atendente.',
            ],
            NativeTool::ChatwootAddLabel->value => [
                'label' => 'Adicionar label',
                'description' => 'Adiciona label na conversa.',
            ],
            NativeTool::ChatwootAssignTeam->value => [
                'label' => 'Atribuir time',
                'description' => 'Atribui a conversa a um time Chatwoot.',
            ],
            NativeTool::ChatwootAssignAgent->value => [
                'label' => 'Atribuir atendente',
                'description' => 'Atribui a conversa a um atendente Chatwoot.',
            ],
            NativeTool::ChatwootGetContact->value => [
                'label' => 'Consultar contato',
                'description' => 'Le os dados de um contato no Chatwoot.',
            ],
            NativeTool::ChatwootUpdateContact->value => [
                'label' => 'Editar contato',
                'description' => 'Atualiza nome, email ou telefone de um contato no Chatwoot.',
            ],
            NativeTool::UpdateContactMemory->value => [
                'label' => 'Registrar memoria',
                'description' => 'Registra um fato sobre o contato (preferencia, restricao, historico) para uso em conversas futuras.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return collect($this->tools())
            ->mapWithKeys(fn (array $tool, string $name): array => [$name => $tool['label']])
            ->all();
    }
}
