import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { OryntraApiClient, OryntraApiError } from './api-client.js';
import type { OryntraMcpConfig } from './config.js';
import { AGENT_DESIGN, GETTING_STARTED, INTAKE, SERVER_INSTRUCTIONS, TOOLS_AND_SCOPES } from './guides.js';
import {
    businessHours,
    contactToolsConfig,
    debounceConfig,
    documentToolsConfig,
    googleCalendarConfig,
    guardConfig,
    handoffConfig,
    memoryConfig,
    productToolsConfig,
    ragConfig,
    resolutionConfig,
} from './schemas.js';

const perPage = z
    .number()
    .int()
    .min(1)
    .max(100)
    .optional()
    .describe('Items per page (1-100, default 20).');

export function createServer(config: OryntraMcpConfig): McpServer {
    const server = new McpServer(
        { name: 'oryntra', version: '0.1.0' },
        { capabilities: { logging: {}, resources: {} }, instructions: SERVER_INSTRUCTIONS },
    );

    const api = new OryntraApiClient(config);

    // ───────────────────────── Guides (resources) ─────────────────────────

    const guides: Array<{ slug: string; title: string; description: string; body: string }> = [
        {
            slug: 'intake',
            title: 'Before You Build: Interview the User',
            description: 'Questions to ask before create_agent — mode, voice, LLM, routing, tools, knowledge.',
            body: INTAKE,
        },
        {
            slug: 'getting-started',
            title: 'Getting Started',
            description: 'Order of operations, workspace scoping, async knowledge, write-only secrets, errors.',
            body: GETTING_STARTED,
        },
        {
            slug: 'agent-design',
            title: 'Designing Agents (LangGraph)',
            description: 'How agents map to the LangGraph graph; modes, specialists, tools_allowlist, RAG.',
            body: AGENT_DESIGN,
        },
        {
            slug: 'tools-and-scopes',
            title: 'Tools, Scopes & Conventions',
            description: 'Ability→tool map, pagination, connectors vs MCP servers.',
            body: TOOLS_AND_SCOPES,
        },
    ];

    for (const guide of guides) {
        server.registerResource(
            guide.slug,
            `oryntra://guide/${guide.slug}`,
            { title: guide.title, description: guide.description, mimeType: 'text/markdown' },
            async (uri) => ({
                contents: [{ uri: uri.href, mimeType: 'text/markdown', text: guide.body }],
            }),
        );
    }

    // ───────────────────────── Connection ─────────────────────────

    server.registerTool(
        'whoami',
        {
            title: 'Who Am I',
            description:
                'Return the workspace and abilities the current token grants. Call this first to confirm the connection and to learn which operations are allowed.',
            inputSchema: {},
        },
        async () => handleTool(() => api.request('GET', '/me')),
    );

    // ───────────────────────── LLM keys ─────────────────────────

    server.registerTool(
        'list_llm_keys',
        {
            title: 'List LLM Keys',
            description:
                'List BYOK LLM provider keys in the workspace (OpenAI/Anthropic/Gemini/Local). A specialist needs one of these to actually answer. Required scope: llmkey:read.',
            inputSchema: { per_page: perPage },
        },
        async (input) => handleTool(() => api.request('GET', api.withQuery('/llm-keys', input))),
    );

    server.registerTool(
        'list_llm_models',
        {
            title: 'List LLM Models',
            description:
                'List the model ids synced for a given LLM key. Use this to discover a valid llm_model value before creating a specialist. Required scope: llmkey:read.',
            inputSchema: {
                llm_key_id: z.number().int().positive().describe('The LLM key id (from list_llm_keys).'),
            },
        },
        async ({ llm_key_id }) => handleTool(() => api.request('GET', `/llm-keys/${llm_key_id}/models`)),
    );

    server.registerTool(
        'create_llm_key',
        {
            title: 'Create LLM Key',
            description:
                'Register a BYOK provider key so agents can call an LLM. The api_key is write-only and stored encrypted. Required scope: llmkey:write.',
            inputSchema: {
                name: z.string().min(1).describe('A label for this key, e.g. "OpenAI Prod".'),
                provider: z.enum(['openai', 'anthropic', 'gemini', 'local']).describe('LLM provider.'),
                api_key: z.string().min(1).describe('The provider API key. Stored encrypted, never returned.'),
                base_url: z.string().optional().describe('Override the provider base URL (required for local/self-hosted).'),
                status: z.enum(['active', 'inactive']).optional().describe('Defaults to active.'),
            },
        },
        async (input) => handleTool(() => api.request('POST', '/llm-keys', input)),
    );

    server.registerTool(
        'delete_llm_key',
        {
            title: 'Delete LLM Key',
            description: 'Delete an LLM key. Required scope: llmkey:write.',
            inputSchema: { llm_key_id: z.number().int().positive() },
        },
        async ({ llm_key_id }) => handleTool(() => api.request('DELETE', `/llm-keys/${llm_key_id}`)),
    );

    // ───────────────────────── Agents ─────────────────────────

    server.registerTool(
        'list_agents',
        {
            title: 'List Agents',
            description: 'List agents in the workspace. Required scope: agent:read.',
            inputSchema: { per_page: perPage },
        },
        async (input) => handleTool(() => api.request('GET', api.withQuery('/agents', input))),
    );

    server.registerTool(
        'get_agent',
        {
            title: 'Get Agent',
            description: 'Fetch one agent by id. Required scope: agent:read.',
            inputSchema: { agent_id: z.number().int().positive() },
        },
        async ({ agent_id }) => handleTool(() => api.request('GET', `/agents/${agent_id}`)),
    );

    server.registerTool(
        'create_agent',
        {
            title: 'Create Agent',
            description:
                'Create an agent. In "single" mode a specialist is auto-created to hold the prompt/LLM/tools — edit it via update_specialist. In "supervisor" mode you add specialists yourself. Required scope: agent:write.',
            inputSchema: {
                name: z.string().min(1).describe('Unique agent name within the workspace.'),
                mode: z.enum(['single', 'supervisor']).describe('"single" = one specialist; "supervisor" = routes to many.'),
                description: z.string().optional(),
                status: z.enum(['active', 'inactive']).optional().describe('Defaults to inactive.'),
                response_mode: z
                    .enum(['automatic', 'suggestion_only', 'human_approval'])
                    .optional()
                    .describe('How replies are delivered. Defaults to automatic.'),
                locale: z.string().optional().describe('BCP-47 locale, e.g. "pt-BR". Defaults to "en".'),
                timezone: z.string().optional().describe('IANA timezone, e.g. "America/Sao_Paulo". Defaults to "UTC".'),
                business_hours: businessHours,
                supervisor_prompt: z.string().optional().describe('Routing prompt (supervisor mode only).'),
                supervisor_llm_key_id: z.number().int().positive().optional(),
                supervisor_llm_model: z.string().optional(),
                debounce_config: debounceConfig,
                guard_config: guardConfig,
                rag_config: ragConfig,
            },
        },
        async (input) => handleTool(() => api.request('POST', '/agents', input)),
    );

    server.registerTool(
        'update_agent',
        {
            title: 'Update Agent',
            description: 'Patch fields on an agent. Only provided fields change. Required scope: agent:write.',
            inputSchema: {
                agent_id: z.number().int().positive(),
                name: z.string().min(1).optional(),
                description: z.string().nullable().optional(),
                status: z.enum(['active', 'inactive']).optional(),
                mode: z.enum(['single', 'supervisor']).optional(),
                response_mode: z.enum(['automatic', 'suggestion_only', 'human_approval']).optional(),
                locale: z.string().optional(),
                timezone: z.string().optional(),
                business_hours: businessHours,
                fallback_specialist_id: z
                    .number()
                    .int()
                    .positive()
                    .nullable()
                    .optional()
                    .describe('Specialist (of this agent) the supervisor routes to when nothing matches. Set after the specialists exist.'),
                supervisor_prompt: z.string().nullable().optional(),
                supervisor_llm_key_id: z.number().int().positive().nullable().optional(),
                supervisor_llm_model: z.string().nullable().optional(),
                debounce_config: debounceConfig,
                guard_config: guardConfig,
                rag_config: ragConfig,
            },
        },
        async ({ agent_id, ...body }) => handleTool(() => api.request('PATCH', `/agents/${agent_id}`, body)),
    );

    server.registerTool(
        'delete_agent',
        {
            title: 'Delete Agent',
            description: 'Delete an agent and its specialists. Required scope: agent:write.',
            inputSchema: { agent_id: z.number().int().positive() },
        },
        async ({ agent_id }) => handleTool(() => api.request('DELETE', `/agents/${agent_id}`)),
    );

    // ───────────────────────── Specialists ─────────────────────────

    server.registerTool(
        'list_specialists',
        {
            title: 'List Specialists',
            description: 'List the specialists of an agent. Required scope: specialist:read.',
            inputSchema: { agent_id: z.number().int().positive() },
        },
        async ({ agent_id }) => handleTool(() => api.request('GET', `/agents/${agent_id}/specialists`)),
    );

    server.registerTool(
        'create_specialist',
        {
            title: 'Create Specialist',
            description:
                'Add a specialist to an agent. The specialist holds the role prompt, the LLM (llm_key_id + llm_model), and the tools allowlist. Create an llm_key first. Required scope: specialist:write.',
            inputSchema: {
                agent_id: z.number().int().positive(),
                name: z.string().min(1),
                role_prompt: z.string().min(1).describe('System prompt defining the specialist behaviour.'),
                llm_key_id: z.number().int().positive().optional().describe('Which BYOK key to use (from list_llm_keys).'),
                llm_model: z.string().optional().describe('Model id (from list_llm_models).'),
                llm_temperature: z.number().min(0).max(2).optional(),
                intent_keywords: z.array(z.string()).optional().describe('Keywords that route to this specialist (supervisor mode).'),
                tools_allowlist: z.array(z.string()).optional().describe('Native tool names / connector slugs this specialist may call.'),
                priority: z.number().int().min(0).optional(),
                confidence_threshold: z.number().min(0).max(1).optional(),
                status: z.enum(['active', 'inactive']).optional(),
                contact_tools_config: contactToolsConfig,
                product_tools_config: productToolsConfig,
                document_tools_config: documentToolsConfig,
                memory_config: memoryConfig,
                resolution_config: resolutionConfig,
                handoff_config: handoffConfig,
                google_calendar_config: googleCalendarConfig,
            },
        },
        async ({ agent_id, ...body }) => handleTool(() => api.request('POST', `/agents/${agent_id}/specialists`, body)),
    );

    server.registerTool(
        'update_specialist',
        {
            title: 'Update Specialist',
            description:
                'Patch a specialist (e.g. set its role_prompt, llm_key_id, llm_model, or tools_allowlist). This is how you configure a single-mode agent. Required scope: specialist:write.',
            inputSchema: {
                specialist_id: z.number().int().positive(),
                name: z.string().min(1).optional(),
                role_prompt: z.string().min(1).optional(),
                llm_key_id: z.number().int().positive().nullable().optional(),
                llm_model: z.string().nullable().optional(),
                llm_temperature: z.number().min(0).max(2).optional(),
                intent_keywords: z.array(z.string()).optional(),
                tools_allowlist: z.array(z.string()).optional(),
                priority: z.number().int().min(0).optional(),
                confidence_threshold: z.number().min(0).max(1).optional(),
                status: z.enum(['active', 'inactive']).optional(),
                contact_tools_config: contactToolsConfig,
                product_tools_config: productToolsConfig,
                document_tools_config: documentToolsConfig,
                memory_config: memoryConfig,
                resolution_config: resolutionConfig,
                handoff_config: handoffConfig,
                google_calendar_config: googleCalendarConfig,
            },
        },
        async ({ specialist_id, ...body }) => handleTool(() => api.request('PATCH', `/specialists/${specialist_id}`, body)),
    );

    server.registerTool(
        'delete_specialist',
        {
            title: 'Delete Specialist',
            description: 'Delete a specialist. Required scope: specialist:write.',
            inputSchema: { specialist_id: z.number().int().positive() },
        },
        async ({ specialist_id }) => handleTool(() => api.request('DELETE', `/specialists/${specialist_id}`)),
    );

    // ───────────────────────── Categories & Products ─────────────────────────

    server.registerTool(
        'list_categories',
        {
            title: 'List Categories',
            description: 'List product categories. Required scope: category:read.',
            inputSchema: { per_page: perPage },
        },
        async (input) => handleTool(() => api.request('GET', api.withQuery('/categories', input))),
    );

    server.registerTool(
        'create_category',
        {
            title: 'Create Category',
            description: 'Create a product category (slug auto-generated). Required scope: category:write.',
            inputSchema: {
                name: z.string().min(1),
                description: z.string().optional(),
            },
        },
        async (input) => handleTool(() => api.request('POST', '/categories', input)),
    );

    server.registerTool(
        'list_products',
        {
            title: 'List Products',
            description:
                'List products. Filter with search (matches name/sku/description and tags/synonyms), category (name or slug), active, min_price, max_price. Required scope: product:read.',
            inputSchema: {
                search: z.string().optional(),
                category: z.string().optional().describe('Category name or slug.'),
                active: z.boolean().optional(),
                min_price: z.number().optional(),
                max_price: z.number().optional(),
                agent_id: z.number().int().positive().optional().describe('Only products visible to this agent (its own + global).'),
                per_page: perPage,
            },
        },
        async (input) => handleTool(() => api.request('GET', api.withQuery('/products', input))),
    );

    server.registerTool(
        'create_product',
        {
            title: 'Create Product',
            description: 'Create a product. Required scope: product:write.',
            inputSchema: {
                name: z.string().min(1),
                category_id: z.number().int().positive().optional(),
                sku: z.string().optional().describe('Unique per workspace.'),
                description: z.string().optional(),
                price: z.number().min(0).optional(),
                active: z.boolean().optional(),
                metadata: z.record(z.string(), z.unknown()).optional().describe('Arbitrary key/value metadata.'),
                tags: z
                    .array(z.string().min(1).max(60))
                    .optional()
                    .describe('Search synonyms/aliases so customers find this product by other words (e.g. "televisor","tv","led" for a Smart TV). Used only to match queries, not shown to the agent.'),
                agent_ids: z
                    .array(z.number().int().positive())
                    .optional()
                    .describe('Scope this product to these agents (from list_agents). Empty/omitted = global (every agent sees it).'),
            },
        },
        async (input) => handleTool(() => api.request('POST', '/products', input)),
    );

    server.registerTool(
        'update_product',
        {
            title: 'Update Product',
            description: 'Patch a product. Required scope: product:write.',
            inputSchema: {
                product_id: z.number().int().positive(),
                name: z.string().min(1).optional(),
                category_id: z.number().int().positive().nullable().optional(),
                sku: z.string().nullable().optional(),
                description: z.string().nullable().optional(),
                price: z.number().min(0).nullable().optional(),
                active: z.boolean().optional(),
                tags: z
                    .array(z.string().min(1).max(60))
                    .nullable()
                    .optional()
                    .describe('Replace the search synonyms/aliases (e.g. "televisor","tv","led"). [] or null clears them. Used only to match queries, not shown to the agent.'),
                agent_ids: z
                    .array(z.number().int().positive())
                    .optional()
                    .describe('Replace the agents this product is scoped to. [] = make it global.'),
            },
        },
        async ({ product_id, ...body }) => handleTool(() => api.request('PATCH', `/products/${product_id}`, body)),
    );

    server.registerTool(
        'delete_product',
        {
            title: 'Delete Product',
            description: 'Delete a product. Required scope: product:write.',
            inputSchema: { product_id: z.number().int().positive() },
        },
        async ({ product_id }) => handleTool(() => api.request('DELETE', `/products/${product_id}`)),
    );

    // ───────────────────────── Knowledge base (RAG) ─────────────────────────

    server.registerTool(
        'list_knowledge',
        {
            title: 'List Knowledge Documents',
            description:
                'List knowledge base documents and their index_status (pending/indexing/indexed/failed). Required scope: knowledge:read.',
            inputSchema: {
                agent_id: z.number().int().positive().optional().describe('Only documents visible to this agent (its own + global).'),
                per_page: perPage,
            },
        },
        async (input) => handleTool(() => api.request('GET', api.withQuery('/knowledge-documents', input))),
    );

    server.registerTool(
        'add_knowledge_from_text',
        {
            title: 'Add Knowledge From Text',
            description:
                'Ingest markdown/plain text into the workspace knowledge base. The document is stored and queued for embedding; it starts in "pending" and becomes "indexed" in the background. Ideal when you generate the content yourself. Required scope: knowledge:write.',
            inputSchema: {
                name: z.string().min(1).describe('Document title.'),
                content: z.string().min(1).describe('Markdown or plain text content to index.'),
                tags: z.array(z.string()).optional(),
                agent_ids: z
                    .array(z.number().int().positive())
                    .optional()
                    .describe('Scope this document to these agents (from list_agents). Empty/omitted = global.'),
            },
        },
        async (input) => handleTool(() => api.request('POST', '/knowledge-documents/from-text', input)),
    );

    server.registerTool(
        'delete_knowledge',
        {
            title: 'Delete Knowledge Document',
            description: 'Delete a knowledge document. Required scope: knowledge:write.',
            inputSchema: { knowledge_document_id: z.number().int().positive() },
        },
        async ({ knowledge_document_id }) =>
            handleTool(() => api.request('DELETE', `/knowledge-documents/${knowledge_document_id}`)),
    );

    // ───────────────────────── External tools ─────────────────────────

    server.registerTool(
        'list_connectors',
        {
            title: 'List HTTP Connectors',
            description: 'List HTTP API connectors the agents can call as tools. Required scope: tool:read.',
            inputSchema: { per_page: perPage },
        },
        async (input) => handleTool(() => api.request('GET', api.withQuery('/connectors', input))),
    );

    server.registerTool(
        'create_connector',
        {
            title: 'Create HTTP Connector',
            description:
                'Create an HTTP connector the agent can call. config defines the request; secret holds credentials (write-only, encrypted). param_schema.properties declares typed args the LLM fills, each with type/description/location/required. Required scope: tool:write.',
            inputSchema: {
                slug: z.string().regex(/^[a-z][a-z0-9_]*$/).describe('Unique snake_case identifier within the workspace.'),
                label: z.string().min(1),
                description: z.string().optional(),
                enabled: z.boolean().optional(),
                config: z
                    .object({
                        http_method: z.enum(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
                        base_url: z.string(),
                        path: z.string().optional().describe('Path with {placeholders} for path params.'),
                        auth_type: z.enum(['none', 'api_key', 'bearer', 'basic']).optional(),
                        param_schema: z.record(z.string(), z.unknown()).optional().describe('{ properties: { name: { type, description, location, required } } }'),
                        response_extraction: z.record(z.string(), z.unknown()).optional(),
                        timeout_seconds: z.number().int().min(1).max(120).optional(),
                    })
                    .describe('Request definition.'),
                secret: z
                    .object({
                        token: z.string().optional(),
                        username: z.string().optional(),
                        password: z.string().optional(),
                    })
                    .optional()
                    .describe('Credentials, stored encrypted and never returned.'),
            },
        },
        async (input) => handleTool(() => api.request('POST', '/connectors', input)),
    );

    server.registerTool(
        'delete_connector',
        {
            title: 'Delete HTTP Connector',
            description: 'Delete an HTTP connector. Required scope: tool:write.',
            inputSchema: { connector_id: z.number().int().positive() },
        },
        async ({ connector_id }) => handleTool(() => api.request('DELETE', `/connectors/${connector_id}`)),
    );

    server.registerTool(
        'list_mcp_servers',
        {
            title: 'List MCP Servers',
            description: 'List MCP servers the agent consumes as tools. Required scope: tool:read.',
            inputSchema: { per_page: perPage },
        },
        async (input) => handleTool(() => api.request('GET', api.withQuery('/mcp-servers', input))),
    );

    server.registerTool(
        'create_mcp_server',
        {
            title: 'Create MCP Server',
            description:
                'Register an MCP server (Streamable HTTP) the agent can consume. secret.token is write-only/encrypted. Use list_mcp_server_tools afterwards to verify connectivity. Required scope: tool:write.',
            inputSchema: {
                slug: z.string().regex(/^[a-z][a-z0-9_]*$/).describe('Unique snake_case identifier.'),
                label: z.string().min(1),
                description: z.string().optional(),
                enabled: z.boolean().optional(),
                config: z.object({
                    base_url: z.string().describe('Streamable HTTP MCP endpoint, e.g. https://n8n.example.com/mcp/abc.'),
                    auth_type: z.enum(['none', 'api_key', 'bearer']).optional(),
                    timeout_seconds: z.number().int().min(1).max(120).optional(),
                }),
                secret: z.object({ token: z.string().optional() }).optional(),
            },
        },
        async (input) => handleTool(() => api.request('POST', '/mcp-servers', input)),
    );

    server.registerTool(
        'list_mcp_server_tools',
        {
            title: 'List MCP Server Tools',
            description:
                'Live discovery: connect to the MCP server and list the tools it exposes (handshake + tools/list). Required scope: tool:read.',
            inputSchema: { mcp_server_id: z.number().int().positive() },
        },
        async ({ mcp_server_id }) => handleTool(() => api.request('GET', `/mcp-servers/${mcp_server_id}/tools`)),
    );

    server.registerTool(
        'delete_mcp_server',
        {
            title: 'Delete MCP Server',
            description: 'Delete an MCP server. Required scope: tool:write.',
            inputSchema: { mcp_server_id: z.number().int().positive() },
        },
        async ({ mcp_server_id }) => handleTool(() => api.request('DELETE', `/mcp-servers/${mcp_server_id}`)),
    );

    // ───────────────────────── Lookups (config-block ids) ─────────────────────────

    server.registerTool(
        'list_chatwoot_teams',
        {
            title: 'List Chatwoot Teams',
            description:
                'List Chatwoot teams in the workspace. Use a returned team_id for handoff_config.team_id. Required scope: specialist:read.',
            inputSchema: {},
        },
        async () => handleTool(() => api.request('GET', '/lookups/chatwoot/teams')),
    );

    server.registerTool(
        'list_chatwoot_agents',
        {
            title: 'List Chatwoot Agents',
            description:
                'List Chatwoot agents (workspace members linked to Chatwoot). Use a returned agent_id for handoff_config.agent_id. Optionally filter by team_id. Required scope: specialist:read.',
            inputSchema: {
                team_id: z.number().int().positive().optional().describe('Only agents in this Chatwoot team.'),
            },
        },
        async (input) => handleTool(() => api.request('GET', api.withQuery('/lookups/chatwoot/agents', input))),
    );

    server.registerTool(
        'list_chatwoot_labels',
        {
            title: 'List Chatwoot Labels',
            description:
                'List Chatwoot label titles in the workspace. Use a title for handoff_config.label_name or resolution_config.label_name. Required scope: specialist:read.',
            inputSchema: {},
        },
        async () => handleTool(() => api.request('GET', '/lookups/chatwoot/labels')),
    );

    server.registerTool(
        'list_calendar_connections',
        {
            title: 'List Google Calendar Connections',
            description:
                'List active Google Calendar connections. Use a returned connection_id for google_calendar_config.connection_id. Required scope: specialist:read.',
            inputSchema: {},
        },
        async () => handleTool(() => api.request('GET', '/lookups/calendar/connections')),
    );

    server.registerTool(
        'list_calendar_calendars',
        {
            title: 'List Calendars Of A Connection',
            description:
                'Live discovery: list the calendars a Google connection can access. Use a returned calendar_id for google_calendar_config.calendar_id. Required scope: specialist:read.',
            inputSchema: {
                connection_id: z.number().int().positive().describe('The connection id (from list_calendar_connections).'),
            },
        },
        async ({ connection_id }) =>
            handleTool(() => api.request('GET', `/lookups/calendar/connections/${connection_id}/calendars`)),
    );

    return server;
}

async function handleTool(callback: () => Promise<unknown>): Promise<{
    content: Array<{ type: 'text'; text: string }>;
    structuredContent?: Record<string, unknown>;
    isError?: boolean;
}> {
    try {
        const result = await callback();

        if (result === null || result === undefined) {
            return { content: [{ type: 'text', text: 'OK' }] };
        }

        const text = typeof result === 'string' ? result : JSON.stringify(result);

        return {
            content: [{ type: 'text', text }],
            structuredContent:
                typeof result === 'object' && result !== null ? (result as Record<string, unknown>) : undefined,
        };
    } catch (error) {
        if (error instanceof OryntraApiError) {
            const detailLines = error.details
                ? Object.entries(error.details)
                      .map(([field, messages]) => `${field}: ${messages.join('; ')}`)
                      .join('\n')
                : '';
            const text =
                detailLines !== ''
                    ? `Oryntra API error ${error.status}: ${error.message}\nValidation:\n${detailLines}`
                    : `Oryntra API error ${error.status}: ${error.message}`;

            return { content: [{ type: 'text', text }], isError: true };
        }

        return {
            content: [{ type: 'text', text: `Unexpected error calling Oryntra API: ${(error as Error).message}` }],
            isError: true,
        };
    }
}
