"""Native LangChain tool-calling for specialists.

The supervisor decision/respond loop only knows how to act on
``respond_text`` / ``request_human_handoff`` / ``request_reroute``. This
module wires the remaining "executable" tools (``chatwoot_update_contact``,
``chatwoot_get_contact``, ``update_contact_memory``) into a proper tool
calling loop using ``ChatModel.bind_tools`` so the specialist can actually
invoke them mid-turn instead of just describing the action in plain text.
"""

from __future__ import annotations

import json
import logging
import time
from dataclasses import dataclass, field
from typing import Any, Literal

from langchain_core.messages import (
    AIMessage,
    HumanMessage,
    SystemMessage,
    ToolMessage,
)
from langchain_core.tools import StructuredTool
from pydantic import BaseModel, ConfigDict, Field, create_model

from oryntra_agent.agent.streaming import emit_event, invoke_or_stream
from oryntra_agent.agent.tools import (
    CallExternalToolRequest,
    CallGoogleCalendarRequest,
    CallMcpToolRequest,
    GetContactRequest,
    QueryDocumentsRequest,
    QueryProductsRequest,
    ResolveConversationRequest,
    SearchKnowledgeBaseRequest,
    SendDocumentRequest,
    UpdateContactMemoryRequest,
    UpdateContactRequest,
    call_external_tool,
    call_google_calendar,
    call_mcp_tool,
    chatwoot_get_contact,
    chatwoot_update_contact,
    query_documents,
    query_products,
    resolve_conversation,
    search_knowledge_base,
    send_document,
    update_contact_memory,
)
from oryntra_agent.api.schemas import (
    ExternalToolConfig,
    McpServerRuntimeConfig,
    McpToolConfig,
    SendDocumentArgs,
)

logger = logging.getLogger(__name__)


EXECUTABLE_TOOLS: frozenset[str] = frozenset(
    {
        "chatwoot_update_contact",
        "chatwoot_get_contact",
        "update_contact_memory",
        "resolve_conversation",
        "query_products",
        "query_documents",
        "search_knowledge_base",
        "send_document",
        "gcal_list_events",
        "gcal_create_event",
        "gcal_update_event",
        "gcal_delete_event",
        "gcal_find_free_slots",
    }
)


GCAL_TOOLS: frozenset[str] = frozenset(
    {
        "gcal_list_events",
        "gcal_create_event",
        "gcal_update_event",
        "gcal_delete_event",
        "gcal_find_free_slots",
    }
)


CONTACT_TOOLS: frozenset[str] = frozenset(
    {
        "chatwoot_update_contact",
        "chatwoot_get_contact",
        "update_contact_memory",
    }
)


MAX_TOOL_ITERATIONS = 4
TOOL_ITERATIONS_HARD_CAP = 20


@dataclass(frozen=True)
class ToolRuntimeContext:
    workspace_id: int
    agent_id: int
    agent_run_id: int
    specialist_id: int | None
    contact_id: int | None
    conversation_id: int
    thread_id: str = ""


class ChatwootUpdateContactArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    name: str | None = Field(default=None, description="Novo nome do contato.")
    email: str | None = Field(default=None, description="Novo email do contato.")
    phone_number: str | None = Field(default=None, description="Novo telefone do contato (E.164).")
    address_postal_code: str | None = Field(
        default=None, description="CEP/codigo postal do endereco de entrega."
    )
    address_street: str | None = Field(
        default=None, description="Rua ou avenida do endereco de entrega."
    )
    address_number: str | None = Field(default=None, description="Numero do endereco de entrega.")
    address_complement: str | None = Field(
        default=None, description="Complemento do endereco de entrega."
    )
    address_neighborhood: str | None = Field(
        default=None, description="Bairro do endereco de entrega."
    )
    address_city: str | None = Field(default=None, description="Cidade do endereco de entrega.")
    address_state: str | None = Field(default=None, description="Estado/UF do endereco de entrega.")
    address_country: str | None = Field(default=None, description="Pais do endereco de entrega.")
    address_reference: str | None = Field(
        default=None, description="Ponto de referencia ou observacoes de entrega."
    )


class ChatwootGetContactArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")


class UpdateContactMemoryArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    type: Literal["preference", "fact", "constraint", "history", "custom"] = Field(
        description="Categoria da memoria.",
    )
    content: str = Field(description="Frase curta descrevendo o fato sobre o cliente.")
    confidence: float | None = Field(
        default=None,
        description="Confianca de 0 a 1 sobre a memoria extraida.",
    )


class ResolveConversationArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    reason: str = Field(
        description="Motivo interno curto pra registro no AgentRun (ex.: 'Cliente confirmou resolucao').",
    )
    resolution_summary: str = Field(
        description="O que foi resolvido. 1-2 frases descrevendo a duvida e a resposta dada.",
    )
    customer_message: str | None = Field(
        default=None,
        description="Mensagem final ao cliente antes do encerramento. Opcional; usa default do especialista quando vazio.",
    )
    label_name: str | None = Field(
        default=None,
        description="Label opcional pra aplicar antes do encerramento. Sobrescreve o default do especialista.",
    )


def build_specialist_tools(
    allowed_tools: list[str],
    ctx: ToolRuntimeContext,
    terminal_state: dict[str, Any] | None = None,
    external_tools: list[ExternalToolConfig] | None = None,
    mcp_servers: list[McpServerRuntimeConfig] | None = None,
) -> list[StructuredTool]:
    tools: list[StructuredTool] = []

    if ctx.contact_id is not None:
        if "chatwoot_update_contact" in allowed_tools:
            tools.append(_make_update_contact_tool(ctx))

        if "chatwoot_get_contact" in allowed_tools:
            tools.append(_make_get_contact_tool(ctx))

        if "update_contact_memory" in allowed_tools:
            tools.append(_make_update_memory_tool(ctx))

    if "resolve_conversation" in allowed_tools and terminal_state is not None:
        tools.append(_make_resolve_conversation_tool(ctx, terminal_state))

    if "query_products" in allowed_tools:
        tools.append(_make_query_products_tool(ctx))

    if "query_documents" in allowed_tools:
        tools.append(_make_query_documents_tool(ctx))

    if "search_knowledge_base" in allowed_tools:
        tools.append(_make_search_knowledge_base_tool(ctx))

    if "send_document" in allowed_tools:
        tools.append(_make_send_document_tool(ctx))

    if "gcal_list_events" in allowed_tools:
        tools.append(_make_gcal_list_events_tool(ctx))
    if "gcal_create_event" in allowed_tools:
        tools.append(_make_gcal_create_event_tool(ctx))
    if "gcal_update_event" in allowed_tools:
        tools.append(_make_gcal_update_event_tool(ctx))
    if "gcal_delete_event" in allowed_tools:
        tools.append(_make_gcal_delete_event_tool(ctx))
    if "gcal_find_free_slots" in allowed_tools:
        tools.append(_make_gcal_find_free_slots_tool(ctx))

    for cfg in external_tools or []:
        tools.append(build_external_tool(cfg, ctx))

    for server in mcp_servers or []:
        for tool_cfg in server.tools:
            tools.append(build_mcp_tool(tool_cfg, ctx))

    return tools


_PARAM_TYPE_MAP: dict[str, type] = {
    "string": str,
    "integer": int,
    "number": float,
    "boolean": bool,
}


def _build_param_schema_args_model(
    model_name: str, param_schema: dict[str, Any]
) -> type[BaseModel]:
    """Build a Pydantic args model from a ``param_schema`` dict (extra keys forbidden)."""
    properties = param_schema.get("properties") if isinstance(param_schema, dict) else None
    fields: dict[str, Any] = {}

    if isinstance(properties, dict):
        for name, definition in properties.items():
            if not isinstance(name, str) or not isinstance(definition, dict):
                continue

            py_type = _PARAM_TYPE_MAP.get(str(definition.get("type", "string")), str)
            description = str(definition.get("description") or "")
            required = bool(definition.get("required", False))

            if required:
                fields[name] = (py_type, Field(description=description))
            else:
                fields[name] = (py_type | None, Field(default=None, description=description))

    return create_model(
        model_name,
        __config__=ConfigDict(extra="forbid"),
        **fields,
    )


def _build_external_args_model(cfg: ExternalToolConfig) -> type[BaseModel]:
    return _build_param_schema_args_model(f"ExternalTool_{cfg.slug}_Args", cfg.param_schema)


def build_external_tool(cfg: ExternalToolConfig, ctx: ToolRuntimeContext) -> StructuredTool:
    args_model = _build_external_args_model(cfg)

    def run(**kwargs: Any) -> str:
        args = {key: value for key, value in kwargs.items() if value is not None}

        try:
            response = call_external_tool(
                CallExternalToolRequest(
                    workspace_id=ctx.workspace_id,
                    agent_id=ctx.agent_id,
                    agent_run_id=ctx.agent_run_id,
                    specialist_id=ctx.specialist_id,
                    conversation_id=ctx.conversation_id,
                    external_tool_slug=cfg.slug,
                    args=args,
                )
            )
        except Exception as exc:
            logger.exception("external tool '%s' call failed", cfg.slug)
            return f"error: external tool '{cfg.slug}' failed ({exc})."

        return response.result

    return StructuredTool.from_function(
        name=cfg.slug,
        description=cfg.description or f"Chama a API externa '{cfg.slug}'.",
        args_schema=args_model,
        func=run,
    )


def build_mcp_tool(cfg: McpToolConfig, ctx: ToolRuntimeContext) -> StructuredTool:
    tool_id = f"{cfg.server_slug}__{cfg.tool_name}"
    args_model = _build_param_schema_args_model(f"McpTool_{tool_id}_Args", cfg.param_schema)

    def run(**kwargs: Any) -> str:
        args = {key: value for key, value in kwargs.items() if value is not None}

        try:
            response = call_mcp_tool(
                CallMcpToolRequest(
                    workspace_id=ctx.workspace_id,
                    agent_id=ctx.agent_id,
                    agent_run_id=ctx.agent_run_id,
                    specialist_id=ctx.specialist_id,
                    conversation_id=ctx.conversation_id,
                    server_slug=cfg.server_slug,
                    session_id=cfg.session_id,
                    tool_name=cfg.tool_name,
                    args=args,
                )
            )
        except Exception as exc:
            logger.exception(
                "mcp tool '%s' on server '%s' call failed", cfg.tool_name, cfg.server_slug
            )
            return f"error: mcp tool '{cfg.tool_name}' on '{cfg.server_slug}' failed ({exc})."

        return response.result

    return StructuredTool.from_function(
        name=tool_id,
        description=cfg.description
        or f"Chama a tool '{cfg.tool_name}' no servidor MCP '{cfg.server_slug}'.",
        args_schema=args_model,
        func=run,
    )


def _make_update_contact_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run(
        name: str | None = None,
        email: str | None = None,
        phone_number: str | None = None,
        address_postal_code: str | None = None,
        address_street: str | None = None,
        address_number: str | None = None,
        address_complement: str | None = None,
        address_neighborhood: str | None = None,
        address_city: str | None = None,
        address_state: str | None = None,
        address_country: str | None = None,
        address_reference: str | None = None,
    ) -> str:
        if not any(
            [
                name,
                email,
                phone_number,
                address_postal_code,
                address_street,
                address_number,
                address_complement,
                address_neighborhood,
                address_city,
                address_state,
                address_country,
                address_reference,
            ]
        ):
            return "error: provide at least one contact or address field."

        try:
            response = chatwoot_update_contact(
                UpdateContactRequest(
                    workspace_id=ctx.workspace_id,
                    agent_id=ctx.agent_id,
                    agent_run_id=ctx.agent_run_id,
                    specialist_id=ctx.specialist_id,
                    contact_id=int(ctx.contact_id),  # type: ignore[arg-type]
                    name=name,
                    email=email,
                    phone_number=phone_number,
                    address_postal_code=address_postal_code,
                    address_street=address_street,
                    address_number=address_number,
                    address_complement=address_complement,
                    address_neighborhood=address_neighborhood,
                    address_city=address_city,
                    address_state=address_state,
                    address_country=address_country,
                    address_reference=address_reference,
                )
            )
        except Exception as exc:
            logger.exception("chatwoot_update_contact tool call failed")
            return f"error: chatwoot_update_contact failed ({exc})."

        if response.unchanged:
            return (
                "noop: o contato ja possuia exatamente esses dados, nada foi atualizado. "
                "NAO chame esta tool de novo para confirmar dado existente."
            )

        return f"ok: contato atualizado. status={response.status}."

    return StructuredTool.from_function(
        name="chatwoot_update_contact",
        description=(
            "Atualiza nome, email ou telefone do contato no Chatwoot e na base local, "
            "e salva endereco de entrega somente na base local Oryntra. "
            "Use quando o cliente informar um novo dado de contato ou endereco."
        ),
        args_schema=ChatwootUpdateContactArgs,
        func=run,
    )


def _make_get_contact_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run() -> str:
        try:
            response = chatwoot_get_contact(
                GetContactRequest(
                    workspace_id=ctx.workspace_id,
                    agent_id=ctx.agent_id,
                    agent_run_id=ctx.agent_run_id,
                    specialist_id=ctx.specialist_id,
                    contact_id=int(ctx.contact_id),  # type: ignore[arg-type]
                )
            )
        except Exception as exc:
            logger.exception("chatwoot_get_contact tool call failed")
            return f"error: chatwoot_get_contact failed ({exc})."

        return f"contact: {response.contact}"

    return StructuredTool.from_function(
        name="chatwoot_get_contact",
        description="Le os dados atuais do contato no Chatwoot (cache local de 5 minutos).",
        args_schema=ChatwootGetContactArgs,
        func=run,
    )


def _make_update_memory_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run(type: Literal["preference", "fact", "constraint", "history", "custom"], content: str, confidence: float | None = None) -> str:
        try:
            response = update_contact_memory(
                UpdateContactMemoryRequest(
                    workspace_id=ctx.workspace_id,
                    agent_id=ctx.agent_id,
                    agent_run_id=ctx.agent_run_id,
                    specialist_id=ctx.specialist_id,
                    contact_id=int(ctx.contact_id),  # type: ignore[arg-type]
                    type=type,
                    content=content,
                    confidence=confidence,
                )
            )
        except Exception as exc:
            logger.exception("update_contact_memory tool call failed")
            return f"error: update_contact_memory failed ({exc})."

        return f"ok: memoria gravada. memory_id={response.memory_id}."

    return StructuredTool.from_function(
        name="update_contact_memory",
        description=(
            "Registra um fato/preferencia/restricao sobre o cliente na memoria longo prazo. "
            "Use quando ouvir uma informacao que sera util em conversas futuras."
        ),
        args_schema=UpdateContactMemoryArgs,
        func=run,
    )


def _make_resolve_conversation_tool(
    ctx: ToolRuntimeContext,
    terminal_state: dict[str, Any],
) -> StructuredTool:
    def run(
        reason: str,
        resolution_summary: str,
        customer_message: str | None = None,
        label_name: str | None = None,
    ) -> str:
        if terminal_state.get("resolved"):
            return "ok: resolve_conversation ja foi chamado neste turno."

        try:
            response = resolve_conversation(
                ResolveConversationRequest(
                    workspace_id=ctx.workspace_id,
                    agent_id=ctx.agent_id,
                    agent_run_id=ctx.agent_run_id,
                    thread_id=ctx.thread_id,
                    conversation_id=ctx.conversation_id,
                    specialist_id=ctx.specialist_id,
                    reason=reason,
                    resolution_summary=resolution_summary,
                    customer_message=customer_message,
                    label_name=label_name,
                )
            )
        except Exception as exc:
            logger.exception("resolve_conversation tool call failed")
            return f"error: resolve_conversation failed ({exc})."

        terminal_state["resolved"] = True
        terminal_state["resolution"] = {
            "reason": reason,
            "resolution_summary": resolution_summary,
            "customer_message": customer_message,
            "label_name": label_name,
            "resolution_id": response.resolution_id,
            "status": response.status,
        }

        return f"ok: conversa marcada como resolvida no Chatwoot. resolution_id={response.resolution_id}."

    return StructuredTool.from_function(
        name="resolve_conversation",
        description=(
            "Encerra a conversa marcando como resolvida no Chatwoot. "
            "Chame quando o cliente confirmar que a duvida foi sanada e nao "
            "precisar de mais nada. Apos essa tool, nao gere mais texto."
        ),
        args_schema=ResolveConversationArgs,
        func=run,
    )


class QueryProductsArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    query: str | None = Field(
        default=None, description="Termo de busca pelo nome ou descricao do produto."
    )
    category: str | None = Field(default=None, description="Filtrar por categoria.")
    min_price: float | None = Field(default=None, description="Preco minimo.")
    max_price: float | None = Field(default=None, description="Preco maximo.")
    limit: int = Field(default=20, description="Quantidade maxima de resultados.")


def _make_query_products_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run(
        query: str | None = None,
        category: str | None = None,
        min_price: float | None = None,
        max_price: float | None = None,
        limit: int = 20,
    ) -> str:
        try:
            response = query_products(
                QueryProductsRequest(
                    workspace_id=ctx.workspace_id,
                    agent_id=ctx.agent_id,
                    agent_run_id=ctx.agent_run_id,
                    specialist_id=ctx.specialist_id,
                    query=query,
                    category=category,
                    min_price=min_price,
                    max_price=max_price,
                    limit=limit,
                )
            )
        except Exception as exc:
            logger.exception("query_products tool call failed")
            return f"error: query_products failed ({exc})."

        if not response.products:
            return "Nenhum produto encontrado."

        lines = ["Produtos encontrados:"]
        for p in response.products[:10]:
            price_str = f"R$ {p['price']:.2f}" if p.get("price") else "preco sob consulta"
            lines.append(f"- {p['name']} ({price_str}) [{p.get('category', 'sem categoria')}]")
            if p.get("description"):
                lines.append(f"  {p['description'][:100]}")
            documents = p.get("documents") or []
            for doc in documents:
                lines.append(
                    f"  Documento: {doc.get('original_filename', 'arquivo')} "
                    f"(document_id={doc.get('id')}, document_type='product')"
                )

        if response.total > len(response.products):
            lines.append(f"... e mais {response.total - len(response.products)} produtos.")

        return "\n".join(lines)

    return StructuredTool.from_function(
        name="query_products",
        description=(
            "Busca produtos do catalogo da empresa. Use para mostrar produtos ao cliente, "
            "informar precos, ou ajudar a escolher. Retorna ate 20 produtos por padrao. "
            "Quando um produto tem documentos anexados, eles aparecem com document_id e "
            "document_type='product' para uso com a tool send_document."
        ),
        args_schema=QueryProductsArgs,
        func=run,
    )


class QueryDocumentsArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    query: str | None = Field(
        default=None, description="Termo de busca pelo titulo, descricao ou nome do arquivo."
    )
    category: str | None = Field(
        default=None,
        description="Filtrar por categoria (ex.: catalog, faq, manual, policy, general).",
    )
    limit: int = Field(default=20, description="Quantidade maxima de resultados.")


def _make_query_documents_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run(
        query: str | None = None,
        category: str | None = None,
        limit: int = 20,
    ) -> str:
        try:
            response = query_documents(
                QueryDocumentsRequest(
                    workspace_id=ctx.workspace_id,
                    agent_id=ctx.agent_id,
                    agent_run_id=ctx.agent_run_id,
                    specialist_id=ctx.specialist_id,
                    query=query,
                    category=category,
                    limit=limit,
                )
            )
        except Exception as exc:
            logger.exception("query_documents tool call failed")
            return f"error: query_documents failed ({exc})."

        if not response.documents:
            return "Nenhum documento encontrado."

        lines = ["Documentos encontrados:"]
        for d in response.documents[:10]:
            title = d.get("title") or d.get("original_filename", "documento")
            lines.append(
                f"- {title} [{d.get('category', 'sem categoria')}] "
                f"(document_id={d.get('id')}, document_type='standalone')"
            )

        if response.total > len(response.documents):
            lines.append(f"... e mais {response.total - len(response.documents)} documentos.")

        return "\n".join(lines)

    return StructuredTool.from_function(
        name="query_documents",
        description=(
            "Busca documentos da biblioteca geral da empresa (catalogos, FAQs, manuais, "
            "politicas) que podem ser enviados ao cliente. Retorna cada documento com "
            "document_id e document_type='standalone' para uso com a tool send_document. "
            "Use quando o cliente pede um material que nao esta vinculado a um produto."
        ),
        args_schema=QueryDocumentsArgs,
        func=run,
    )


class SearchKnowledgeBaseArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    query: str = Field(
        description="Pergunta ou termo a buscar na base de conhecimento do workspace.",
    )
    top_k: int = Field(default=5, description="Quantidade maxima de trechos a retornar (1-20).")
    tags: list[str] | None = Field(
        default=None,
        description="Filtrar a busca por tags dos documentos (opcional).",
    )


def _make_search_knowledge_base_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run(
        query: str,
        top_k: int = 5,
        tags: list[str] | None = None,
    ) -> str:
        try:
            response = search_knowledge_base(
                SearchKnowledgeBaseRequest(
                    workspace_id=ctx.workspace_id,
                    agent_id=ctx.agent_id,
                    agent_run_id=ctx.agent_run_id,
                    specialist_id=ctx.specialist_id,
                    query=query,
                    top_k=max(1, min(20, top_k)),
                    tags=tags,
                )
            )
        except Exception as exc:
            logger.exception("search_knowledge_base tool call failed")
            return f"error: search_knowledge_base failed ({exc})."

        if not response.hits:
            return "Nenhum trecho relevante encontrado na base de conhecimento."

        lines = ["Trechos relevantes da base de conhecimento:"]
        for hit in response.hits[:top_k]:
            score = hit.get("score")
            content = str(hit.get("content", "")).strip()
            doc_id = hit.get("agent_document_id")
            prefix = f"[doc {doc_id}"
            if isinstance(score, (int, float)):
                prefix += f", score {score:.2f}"
            prefix += "]"
            lines.append(f"{prefix} {content}")

        return "\n\n".join(lines)

    return StructuredTool.from_function(
        name="search_knowledge_base",
        description=(
            "Busca semantica na base de conhecimento do workspace (documentos vetorizados). "
            "Retorna trechos relevantes para fundamentar a resposta ao cliente. NAO envia "
            "arquivos — use apenas para consultar informacao. Prefira esta tool quando "
            "precisar de detalhes que estao em documentos internos da empresa."
        ),
        args_schema=SearchKnowledgeBaseArgs,
        func=run,
    )


def _make_send_document_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run(document_ids: list[int], document_type: Literal["product", "standalone"], caption: str = "") -> str:
        try:
            response = send_document(
                SendDocumentRequest(
                    workspace_id=ctx.workspace_id,
                    agent_run_id=ctx.agent_run_id,
                    document_ids=document_ids,
                    document_type=document_type,
                    caption=caption,
                    conversation_id=ctx.conversation_id,
                )
            )
        except Exception as exc:
            logger.exception("send_document tool call failed")
            return f"error: send_document failed ({exc})."

        if response.sent:
            joined = ", ".join(response.filenames) or str(response.count)
            return f"{response.count} documento(s) enviado(s) com sucesso: {joined}."

        return f"Falha ao enviar documento(s): {response.error or 'erro desconhecido'}"

    return StructuredTool.from_function(
        name="send_document",
        description=(
            "Envia um ou mais documentos (PDF, imagem) previamente cadastrados ao cliente "
            "via Chatwoot, numa unica mensagem. Use quando o cliente pede um catálogo, "
            "fotos de um produto, planta, ficha técnica, etc. Passe varios IDs em document_ids "
            "para enviar uma galeria de uma vez. Informe document_type='product' para IDs "
            "vindos de query_products ou document_type='standalone' para IDs de query_documents."
        ),
        args_schema=SendDocumentArgs,
        func=run,
    )


# ---------------------------------------------------------------------------
# Google Calendar tools
# ---------------------------------------------------------------------------


class GcalListEventsArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    time_min: str = Field(
        description="Início do intervalo (ISO 8601, ex.: 2026-06-01T00:00:00Z).",
    )
    time_max: str = Field(
        description="Fim do intervalo (ISO 8601, ex.: 2026-06-08T23:59:59Z).",
    )
    query: str | None = Field(
        default=None,
        description="Filtro textual (opcional) que casa contra título/descrição/participantes.",
    )
    max_results: int = Field(default=25, ge=1, le=250, description="Quantidade máxima de eventos.")
    time_zone: str = Field(default="UTC", description="Timezone IANA, ex.: America/Sao_Paulo.")


class GcalCreateEventArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    summary: str = Field(description="Título do evento.")
    start: str = Field(
        description="Início (ISO 8601 com timezone, ex.: 2026-06-01T10:00:00-03:00)."
    )
    end: str = Field(description="Fim (ISO 8601 com timezone).")
    description: str | None = Field(default=None, description="Descrição/notas do evento.")
    location: str | None = Field(default=None, description="Local físico ou link de reunião.")
    attendees: list[str] | None = Field(
        default=None,
        description="Lista de emails dos convidados. Cada convidado recebe email do Google.",
    )
    time_zone: str = Field(default="UTC", description="Timezone IANA do evento.")
    notify_attendees: bool | None = Field(
        default=None,
        description="Se True, envia email aos convidados. Se None, usa default do especialista.",
    )


class GcalUpdateEventArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    event_id: str = Field(description="ID do evento Google a atualizar (vindo de list_events).")
    summary: str | None = Field(default=None, description="Novo título.")
    description: str | None = Field(default=None, description="Nova descrição.")
    location: str | None = Field(default=None, description="Novo local.")
    start: str | None = Field(default=None, description="Novo início (ISO 8601).")
    end: str | None = Field(default=None, description="Novo fim (ISO 8601).")
    attendees: list[str] | None = Field(
        default=None, description="Nova lista de emails de convidados."
    )
    time_zone: str | None = Field(default=None, description="Timezone IANA.")
    notify_attendees: bool | None = Field(
        default=None,
        description="Notificar convidados sobre a mudança. None usa default.",
    )


class GcalDeleteEventArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    event_id: str = Field(description="ID do evento Google a deletar.")
    notify_attendees: bool | None = Field(
        default=None,
        description="Notificar convidados sobre cancelamento. None usa default.",
    )


class GcalFindFreeSlotsArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    duration_minutes: int = Field(ge=5, description="Duração mínima do slot livre em minutos.")
    range_start: str = Field(description="Início da janela de busca (ISO 8601).")
    range_end: str = Field(description="Fim da janela de busca (ISO 8601).")
    time_zone: str = Field(default="UTC", description="Timezone IANA.")
    calendar_ids: list[str] | None = Field(
        default=None,
        description="Calendários adicionais pra cruzar busy. Vazio = apenas o calendário do specialist.",
    )


def _invoke_gcal(ctx: ToolRuntimeContext, tool_name: str, args: dict[str, Any]) -> str:
    clean_args = {k: v for k, v in args.items() if v is not None}
    try:
        response = call_google_calendar(
            CallGoogleCalendarRequest(
                workspace_id=ctx.workspace_id,
                agent_id=ctx.agent_id,
                agent_run_id=ctx.agent_run_id,
                specialist_id=int(ctx.specialist_id),  # type: ignore[arg-type]
                conversation_id=ctx.conversation_id,
                tool_name=tool_name,
                args=clean_args,
            )
        )
    except Exception as exc:
        logger.exception("%s tool call failed", tool_name)
        return f"error: {tool_name} failed ({exc})."

    if not response.success:
        return f"error: {tool_name} returned failure: {response.error or 'desconhecido'}"

    return response.result


def _make_gcal_list_events_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run(
        time_min: str,
        time_max: str,
        query: str | None = None,
        max_results: int = 25,
        time_zone: str = "UTC",
    ) -> str:
        return _invoke_gcal(
            ctx,
            "gcal_list_events",
            {
                "time_min": time_min,
                "time_max": time_max,
                "query": query,
                "max_results": max_results,
                "time_zone": time_zone,
            },
        )

    return StructuredTool.from_function(
        name="gcal_list_events",
        description=(
            "Lista eventos do Google Calendar do specialist entre time_min e time_max. "
            "Use pra checar agenda antes de propor horário, ou pra mostrar próximos compromissos."
        ),
        args_schema=GcalListEventsArgs,
        func=run,
    )


def _make_gcal_create_event_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run(
        summary: str,
        start: str,
        end: str,
        description: str | None = None,
        location: str | None = None,
        attendees: list[str] | None = None,
        time_zone: str = "UTC",
        notify_attendees: bool | None = None,
    ) -> str:
        return _invoke_gcal(
            ctx,
            "gcal_create_event",
            {
                "summary": summary,
                "start": start,
                "end": end,
                "description": description,
                "location": location,
                "attendees": attendees,
                "time_zone": time_zone,
                "notify_attendees": notify_attendees,
            },
        )

    return StructuredTool.from_function(
        name="gcal_create_event",
        description=(
            "Cria um evento no Google Calendar do specialist. Use pra agendar reunião, "
            "visita, ligação ou compromisso. Por padrão, se houver conflito no intervalo "
            "(evento existente), o sistema bloqueia automaticamente — proponha outro horário. "
            "Confirme data/hora/participantes com o cliente antes de criar — a ação é imediata "
            "e notifica convidados por email."
        ),
        args_schema=GcalCreateEventArgs,
        func=run,
    )


def _make_gcal_update_event_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run(
        event_id: str,
        summary: str | None = None,
        description: str | None = None,
        location: str | None = None,
        start: str | None = None,
        end: str | None = None,
        attendees: list[str] | None = None,
        time_zone: str | None = None,
        notify_attendees: bool | None = None,
    ) -> str:
        return _invoke_gcal(
            ctx,
            "gcal_update_event",
            {
                "event_id": event_id,
                "summary": summary,
                "description": description,
                "location": location,
                "start": start,
                "end": end,
                "attendees": attendees,
                "time_zone": time_zone,
                "notify_attendees": notify_attendees,
            },
        )

    return StructuredTool.from_function(
        name="gcal_update_event",
        description=(
            "Atualiza um evento existente do Google Calendar (remarcar horário, mudar título, "
            "ajustar convidados). Precisa do event_id retornado por gcal_list_events ou "
            "gcal_create_event. Passe só os campos que mudaram."
        ),
        args_schema=GcalUpdateEventArgs,
        func=run,
    )


def _make_gcal_delete_event_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run(event_id: str, notify_attendees: bool | None = None) -> str:
        return _invoke_gcal(
            ctx,
            "gcal_delete_event",
            {"event_id": event_id, "notify_attendees": notify_attendees},
        )

    return StructuredTool.from_function(
        name="gcal_delete_event",
        description=(
            "Remove um evento do Google Calendar. Use quando o cliente cancelar um compromisso. "
            "A ação é definitiva — confirme antes de chamar."
        ),
        args_schema=GcalDeleteEventArgs,
        func=run,
    )


def _make_gcal_find_free_slots_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    def run(
        duration_minutes: int,
        range_start: str,
        range_end: str,
        time_zone: str = "UTC",
        calendar_ids: list[str] | None = None,
    ) -> str:
        return _invoke_gcal(
            ctx,
            "gcal_find_free_slots",
            {
                "duration_minutes": duration_minutes,
                "range_start": range_start,
                "range_end": range_end,
                "time_zone": time_zone,
                "calendar_ids": calendar_ids,
            },
        )

    return StructuredTool.from_function(
        name="gcal_find_free_slots",
        description=(
            "Acha janelas livres na agenda do specialist (e opcionalmente em calendários "
            "adicionais via calendar_ids) com pelo menos duration_minutes de duração, "
            "dentro do intervalo [range_start, range_end]. Use pra propor horários ao cliente."
        ),
        args_schema=GcalFindFreeSlotsArgs,
        func=run,
    )


@dataclass
class LlmUsage:
    input_tokens: int = 0
    output_tokens: int = 0
    latency_ms: int = 0


@dataclass(frozen=True)
class ToolLoopResult:
    text: str | None
    tool_calls: list[dict[str, Any]]
    debug_prompt: dict[str, str] | None = None
    resolved: bool = False
    resolution: dict[str, Any] | None = None
    total_usage: LlmUsage = field(default_factory=LlmUsage)


def run_specialist_tool_loop(
    chat_model: Any,
    tools: list[StructuredTool],
    system_prompt: str,
    user_prompt: str,
    max_iterations: int = MAX_TOOL_ITERATIONS,
    terminal_state: dict[str, Any] | None = None,
) -> ToolLoopResult:
    """Run a bounded ReAct loop: invoke the model, dispatch any tool calls,
    feed back ``ToolMessage``s, and repeat until the model returns plain text,
    a terminal tool (e.g. ``resolve_conversation``) flips ``terminal_state``,
    or we hit ``max_iterations`` (clamped to ``TOOL_ITERATIONS_HARD_CAP``).
    """

    if not tools:
        raise ValueError("run_specialist_tool_loop requires at least one tool.")

    iterations = max(1, min(max_iterations, TOOL_ITERATIONS_HARD_CAP))

    chat_with_tools = chat_model.bind_tools(tools)
    tool_by_name = {tool.name: tool for tool in tools}
    messages: list[Any] = [
        SystemMessage(content=system_prompt),
        HumanMessage(content=user_prompt),
    ]
    tool_calls_trace: list[dict[str, Any]] = []

    total_usage = LlmUsage()

    # Cache of already-executed (tool, args) calls. A weak model can emit the
    # same tool call repeatedly (e.g. an empty-arg call that errors); we must not
    # hit the underlying tool/MCP server again — reuse the prior result so the
    # bounded loop converges instead of spamming external calls.
    executed_calls: dict[str, str] = {}

    def _call_signature(call_name: str | None, call_args: Any) -> str:
        try:
            encoded_args = json.dumps(call_args or {}, sort_keys=True, default=str)
        except (TypeError, ValueError):
            encoded_args = str(call_args)

        return f"{call_name}:{encoded_args}"

    def _terminal_result() -> ToolLoopResult:
        state = terminal_state or {}
        resolution = state.get("resolution") if isinstance(state, dict) else None
        return ToolLoopResult(
            text=None,
            tool_calls=tool_calls_trace,
            resolved=True,
            resolution=resolution if isinstance(resolution, dict) else None,
            total_usage=total_usage,
        )

    for _ in range(iterations):
        try:
            ai_message, usage = track_llm_invoke(chat_with_tools, messages)
            total_usage.input_tokens += usage.input_tokens
            total_usage.output_tokens += usage.output_tokens
            total_usage.latency_ms += usage.latency_ms
        except Exception:
            logger.exception("specialist tool loop invoke failed")
            return ToolLoopResult(text=None, tool_calls=tool_calls_trace, total_usage=total_usage)

        messages.append(ai_message)
        pending_calls = getattr(ai_message, "tool_calls", None) or []

        if not pending_calls:
            content = ai_message.content
            text = _extract_text(content)
            return ToolLoopResult(text=text, tool_calls=tool_calls_trace, total_usage=total_usage)

        for call in pending_calls:
            name = call.get("name") if isinstance(call, dict) else getattr(call, "name", None)
            args = call.get("args") if isinstance(call, dict) else getattr(call, "args", {})
            call_id = call.get("id") if isinstance(call, dict) else getattr(call, "id", "")

            tool = tool_by_name.get(name) if isinstance(name, str) else None
            signature = _call_signature(name, args)

            if signature in executed_calls:
                # Identical call already ran this turn: reuse the result instead
                # of invoking the tool/MCP again. Nudge the model to stop looping.
                cached = executed_calls[signature]
                messages.append(
                    ToolMessage(
                        content=(
                            f"{cached}\n\n(Resultado ja obtido nesta conversa para os mesmos parametros. "
                            "Use este valor e prossiga; nao chame a mesma ferramenta de novo.)"
                        ),
                        tool_call_id=str(call_id) if call_id else "",
                    )
                )

                continue

            emit_event({"type": "tool_call", "tool": name, "input": args or {}})

            if tool is None:
                result = f"error: tool '{name}' is not available."
            else:
                try:
                    result = tool.invoke(args or {})
                except Exception as exc:
                    logger.exception("tool dispatch failed")
                    result = f"error: tool '{name}' raised {exc}."

            truncated_output = _truncate(str(result), 500)
            executed_calls[signature] = truncated_output
            emit_event({"type": "tool_result", "tool": name, "output": truncated_output})

            tool_calls_trace.append(
                {
                    "tool": name,
                    "input": args or {},
                    "output": truncated_output,
                }
            )
            messages.append(
                ToolMessage(
                    content=str(result),
                    tool_call_id=str(call_id) if call_id else "",
                )
            )

        if terminal_state is not None and terminal_state.get("resolved"):
            return _terminal_result()

    logger.warning(
        "specialist tool loop exhausted iterations",
        extra={"max_iterations": iterations},
    )
    return ToolLoopResult(text=None, tool_calls=tool_calls_trace, total_usage=total_usage)


def _extract_text(content: Any) -> str | None:
    if isinstance(content, str):
        text = content.strip()
        return text or None

    if isinstance(content, list):
        parts = [
            part.get("text", "").strip()
            for part in content
            if isinstance(part, dict) and isinstance(part.get("text"), str)
        ]
        joined = "\n".join(part for part in parts if part)
        return joined or None

    return None


def _truncate(value: str, limit: int) -> str:
    if len(value) <= limit:
        return value
    return value[: limit - 3] + "..."


def track_llm_invoke(
    chat_with_tools: Any,
    messages: list[Any],
) -> tuple[AIMessage, LlmUsage]:
    start = time.perf_counter()
    ai_message: AIMessage = invoke_or_stream(chat_with_tools, messages)
    latency_ms = int((time.perf_counter() - start) * 1000)

    input_tokens = 0
    output_tokens = 0
    usage_metadata = getattr(ai_message, "usage_metadata", None)
    if usage_metadata:
        input_tokens = usage_metadata.get("input_tokens", 0)
        output_tokens = usage_metadata.get("output_tokens", 0)

    return ai_message, LlmUsage(
        input_tokens=input_tokens,
        output_tokens=output_tokens,
        latency_ms=latency_ms,
    )


__all__ = [
    "EXECUTABLE_TOOLS",
    "GCAL_TOOLS",
    "MAX_TOOL_ITERATIONS",
    "LlmUsage",
    "ToolLoopResult",
    "ToolRuntimeContext",
    "build_external_tool",
    "build_specialist_tools",
    "run_specialist_tool_loop",
    "track_llm_invoke",
]
