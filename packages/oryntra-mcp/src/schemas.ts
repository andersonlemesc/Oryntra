/**
 * Zod shapes for the agent/specialist config blocks. The REST API stores these as loose
 * JSON; these schemas mirror the Oryntra panel forms so the LLM fills the right shape.
 *
 * Fields that reference external numeric IDs (Chatwoot team/agent, Google connection) are
 * typed but flagged "Advanced": the LLM should leave them unset unless the user supplies
 * the id from the panel, since there is no lookup tool for them here yet.
 */
import { z } from 'zod';

const handoffPriority = z.enum(['low', 'normal', 'high', 'urgent']);

// ───────────────────────── Agent-level ─────────────────────────

export const debounceConfig = z
    .object({
        enabled: z.boolean().optional(),
        max_messages: z.number().int().min(1).optional(),
        window_seconds: z.number().int().min(0).optional(),
        max_wait_seconds: z.number().int().min(0).optional(),
    })
    .optional()
    .describe('Message debounce: batch rapid consecutive customer messages before the agent replies.');

export const guardConfig = z
    .object({
        block_sensitive_data: z.boolean().optional(),
        block_prompt_injection: z.boolean().optional(),
        require_rag_for_answers: z.boolean().optional(),
        low_confidence_threshold: z.number().min(0).max(1).nullable().optional(),
        handoff_on_low_confidence: z.boolean().optional(),
    })
    .optional()
    .describe('Guardrails applied to every reply (sensitive-data / prompt-injection blocking, low-confidence handling).');

export const ragConfig = z
    .object({
        enabled: z.boolean().optional(),
        top_k: z.number().int().min(1).nullable().optional(),
        min_score: z.number().min(0).max(1).nullable().optional(),
        answer_only_with_context: z.boolean().optional().describe('If true, only answer when the knowledge base returns context.'),
    })
    .optional()
    .describe('Retrieval settings for the knowledge base (RAG).');

// ───────────────────────── Specialist-level ─────────────────────────

export const contactToolsConfig = z
    .object({ update_enabled: z.boolean().optional() })
    .optional()
    .describe('Whether the specialist may update the Chatwoot contact (needs chatwoot_update_contact in tools_allowlist).');

export const productToolsConfig = z
    .object({ query_enabled: z.boolean().optional() })
    .optional()
    .describe('Whether the specialist may query the product catalog (pairs with query_products).');

export const documentToolsConfig = z
    .object({
        query_enabled: z.boolean().optional(),
        send_enabled: z.boolean().optional(),
        allowed_categories: z
            .array(z.enum(['catalog', 'faq', 'manual', 'policy', 'general', 'knowledge']))
            .optional()
            .describe('Document categories the specialist may send to the customer.'),
    })
    .optional()
    .describe('Standalone document tools (query_documents / send_document).');

export const memoryConfig = z
    .object({
        extraction_enabled: z.boolean().optional().describe('Extract contact memory via LLM each turn (extra token cost).'),
        extraction_types: z
            .array(z.enum(['preference', 'fact', 'constraint', 'history', 'custom']))
            .optional(),
        injection_enabled: z.boolean().optional().describe('Inject the contact\'s recent memories into the system prompt.'),
        injection_limit: z.number().int().min(1).max(200).nullable().optional().describe('Top N memories by recency; null = all.'),
        max_tool_iterations: z.number().int().min(1).max(20).optional().describe('Tool-calling iterations per turn (default 4).'),
    })
    .optional()
    .describe('Contact memory extraction/injection and the per-turn tool-calling budget.');

export const resolutionConfig = z
    .object({
        enabled: z.boolean().optional(),
        label_name: z.string().nullable().optional().describe('Chatwoot label to apply on resolution.'),
        customer_message: z.string().nullable().optional(),
        rules: z
            .array(
                z.object({
                    name: z.string(),
                    enabled: z.boolean().optional(),
                    reason: z.string().optional().describe('Internal reason.'),
                    customer_message: z.string().optional(),
                    label_name: z.string().optional(),
                }),
            )
            .optional()
            .describe('Situations that auto-close the conversation.'),
    })
    .optional()
    .describe('Auto-resolution of the conversation (pairs with resolve_conversation).');

export const handoffConfig = z
    .object({
        enabled: z.boolean().optional().describe('Allow handing off to a human.'),
        summary_llm_enabled: z.boolean().optional().describe('Generate an LLM summary at handoff.'),
        default_priority: handoffPriority.optional(),
        customer_message: z.string().optional().describe('Message shown to the customer at handoff.'),
        label_name: z.string().nullable().optional().describe('Chatwoot label to apply.'),
        private_note_template: z.string().optional(),
        team_enabled: z.boolean().optional().describe('Route the handoff to a Chatwoot team.'),
        team_id: z
            .number()
            .int()
            .positive()
            .nullable()
            .optional()
            .describe('Chatwoot team id — get it from list_chatwoot_teams.'),
        agent_id: z
            .number()
            .int()
            .positive()
            .nullable()
            .optional()
            .describe('Chatwoot agent id — get it from list_chatwoot_agents.'),
        rules: z
            .array(
                z.object({
                    name: z.string(),
                    enabled: z.boolean().optional(),
                    keywords: z.array(z.string()).optional(),
                    priority: handoffPriority.optional(),
                    reason: z.string().optional(),
                    customer_message: z.string().optional(),
                }),
            )
            .optional()
            .describe('Situations that trigger a handoff.'),
    })
    .optional()
    .describe('Human handoff. Needs resolve_conversation/handoff tooling; team_id/agent_id are Chatwoot ids from the panel.');

export const googleCalendarConfig = z
    .object({
        enabled: z.boolean().optional(),
        notify_attendees_default: z.boolean().optional(),
        allow_conflicts: z.boolean().optional(),
        connection_id: z
            .number()
            .int()
            .positive()
            .nullable()
            .optional()
            .describe('Google connection id — get it from list_calendar_connections. Required when enabled.'),
        calendar_id: z
            .string()
            .nullable()
            .optional()
            .describe('Calendar id — get it from list_calendar_calendars. Required when enabled.'),
    })
    .optional()
    .describe('Google Calendar access for the gcal_* tools.');
