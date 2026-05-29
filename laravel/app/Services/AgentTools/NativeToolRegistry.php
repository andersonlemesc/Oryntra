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
            NativeTool::ResolveConversation->value => [
                'label' => 'Encerrar conversa',
                'description' => 'Encerra a conversa marcando como resolvida no Chatwoot quando a IA solucionou a duvida do cliente.',
            ],
            NativeTool::QueryProducts->value => [
                'label' => 'Consultar produtos',
                'description' => 'Busca produtos do catalogo por nome, categoria ou termo. Retorna lista com precos e descricao.',
            ],
            NativeTool::QueryDocuments->value => [
                'label' => 'Consultar documentos',
                'description' => 'Busca documentos da biblioteca geral (catalogos, FAQs, manuais) por categoria ou termo. Retorna IDs para envio via send_document.',
            ],
            NativeTool::SendDocument->value => [
                'label' => 'Enviar documento',
                'description' => 'Envia um documento (PDF, imagem) ao cliente via Chatwoot.',
            ],
            NativeTool::GcalListEvents->value => [
                'label' => 'Google Calendar — listar eventos',
                'description' => 'Lista eventos da agenda Google em um intervalo de tempo. Aceita filtro de texto.',
            ],
            NativeTool::GcalCreateEvent->value => [
                'label' => 'Google Calendar — criar evento',
                'description' => 'Cria um evento na agenda Google com título, início, fim e convidados opcionais.',
            ],
            NativeTool::GcalUpdateEvent->value => [
                'label' => 'Google Calendar — editar evento',
                'description' => 'Atualiza campos de um evento existente da agenda Google.',
            ],
            NativeTool::GcalDeleteEvent->value => [
                'label' => 'Google Calendar — deletar evento',
                'description' => 'Remove um evento da agenda Google.',
            ],
            NativeTool::GcalFindFreeSlots->value => [
                'label' => 'Google Calendar — achar horários livres',
                'description' => 'Busca janelas livres na agenda Google (FreeBusy) em um intervalo.',
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
