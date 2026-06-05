import type { OryntraMcpConfig } from './config.js';

export class OryntraApiError extends Error {
    public readonly status: number;

    public readonly details?: Record<string, string[]>;

    public constructor(message: string, status: number, details?: Record<string, string[]>) {
        super(message);
        this.name = 'OryntraApiError';
        this.status = status;
        this.details = details;
    }
}

interface ValidationErrorPayload {
    message?: string;
    errors?: Record<string, string[]>;
}

export class OryntraApiClient {
    private readonly config: OryntraMcpConfig;

    public constructor(config: OryntraMcpConfig) {
        this.config = config;
    }

    /**
     * Perform an authenticated JSON request against the Oryntra API.
     */
    public async request(method: string, path: string, body?: unknown): Promise<unknown> {
        const response = await fetch(`${this.config.apiUrl}${path}`, {
            method,
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${this.config.apiToken}`,
                ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });

        if (response.status === 204) {
            return null;
        }

        const contentType = response.headers.get('content-type') ?? '';
        const payload = contentType.includes('application/json')
            ? await response.json()
            : await response.text();

        if (!response.ok) {
            throw toApiError(payload, response.status);
        }

        return payload;
    }

    /**
     * Build a querystring path, skipping null/undefined params.
     */
    public withQuery(path: string, params: Record<string, unknown>): string {
        const search = new URLSearchParams();

        for (const [key, value] of Object.entries(params)) {
            if (value !== undefined && value !== null && value !== '') {
                search.set(key, String(value));
            }
        }

        const query = search.toString();

        return query ? `${path}?${query}` : path;
    }

    /**
     * Upload bytes to a presigned PUT URL. The URL is already signed for its
     * host, so we send the raw body without overriding the Host header.
     */
    public async putToPresignedUrl(url: string, body: string | Uint8Array): Promise<void> {
        const response = await fetch(url, { method: 'PUT', body });

        if (!response.ok) {
            throw new OryntraApiError(`Presigned upload failed (HTTP ${response.status}).`, response.status);
        }
    }
}

function toApiError(payload: unknown, status: number): OryntraApiError {
    if (isValidationErrorPayload(payload)) {
        return new OryntraApiError(
            payload.message ?? `Oryntra API request failed with status ${status}.`,
            status,
            payload.errors,
        );
    }

    if (typeof payload === 'string' && payload.trim() !== '') {
        return new OryntraApiError(payload, status);
    }

    return new OryntraApiError(`Oryntra API request failed with status ${status}.`, status);
}

function isValidationErrorPayload(value: unknown): value is ValidationErrorPayload {
    return typeof value === 'object' && value !== null;
}
