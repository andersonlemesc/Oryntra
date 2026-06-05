<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Catalog of abilities (scopes) an API token may grant. Tokens are scoped to a
 * single workspace; abilities further restrict what the token can do within it.
 */
class ApiTokenAbilities
{
    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function catalog(): array
    {
        return [
            ['value' => 'agent:read', 'label' => 'Agentes · leitura', 'description' => 'Listar e ler agentes.'],
            ['value' => 'agent:write', 'label' => 'Agentes · escrita', 'description' => 'Criar, editar e remover agentes.'],
            ['value' => 'specialist:read', 'label' => 'Especialistas · leitura', 'description' => 'Listar e ler especialistas de um agente.'],
            ['value' => 'specialist:write', 'label' => 'Especialistas · escrita', 'description' => 'Criar, editar e remover especialistas.'],
            ['value' => 'llmkey:read', 'label' => 'Chaves LLM · leitura', 'description' => 'Listar chaves LLM e modelos disponíveis.'],
            ['value' => 'llmkey:write', 'label' => 'Chaves LLM · escrita', 'description' => 'Cadastrar, editar e remover chaves LLM (BYOK).'],
            ['value' => 'category:read', 'label' => 'Categorias · leitura', 'description' => 'Listar e ler categorias de produto.'],
            ['value' => 'category:write', 'label' => 'Categorias · escrita', 'description' => 'Criar, editar e remover categorias.'],
            ['value' => 'product:read', 'label' => 'Produtos · leitura', 'description' => 'Listar e ler produtos.'],
            ['value' => 'product:write', 'label' => 'Produtos · escrita', 'description' => 'Criar, editar, importar e remover produtos.'],
            ['value' => 'media:read', 'label' => 'Mídias · leitura', 'description' => 'Listar mídias e gerar URLs de download.'],
            ['value' => 'media:write', 'label' => 'Mídias · escrita', 'description' => 'Subir e remover mídias de produto e documentos enviáveis.'],
            ['value' => 'knowledge:read', 'label' => 'Base de conhecimento · leitura', 'description' => 'Listar e ler documentos da base de conhecimento (RAG).'],
            ['value' => 'knowledge:write', 'label' => 'Base de conhecimento · escrita', 'description' => 'Ingerir e remover documentos da base de conhecimento (RAG).'],
            ['value' => 'tool:read', 'label' => 'Ferramentas externas · leitura', 'description' => 'Listar connectors HTTP e servidores MCP.'],
            ['value' => 'tool:write', 'label' => 'Ferramentas externas · escrita', 'description' => 'Criar, editar e remover connectors HTTP e servidores MCP.'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_map(fn (array $entry): string => $entry['value'], self::catalog());
    }
}
