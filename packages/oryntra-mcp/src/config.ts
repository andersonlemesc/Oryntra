import { readFileSync } from 'node:fs';

export interface OryntraMcpConfig {
    apiUrl: string;
    apiToken: string;
}

const DEFAULT_API_URL = 'http://localhost:8080/api/v1';

export function loadConfig(env: NodeJS.ProcessEnv = process.env): OryntraMcpConfig {
    const apiUrl = normalizeApiUrl(env.ORYNTRA_API_URL ?? DEFAULT_API_URL);
    const apiToken = resolveSecret(env.ORYNTRA_API_TOKEN, env.ORYNTRA_API_TOKEN_FILE);

    if (!apiToken) {
        throw new Error('Missing ORYNTRA_API_TOKEN (or ORYNTRA_API_TOKEN_FILE) environment variable.');
    }

    return { apiUrl, apiToken };
}

export function normalizeApiUrl(value: string): string {
    return value.trim().replace(/\/+$/, '');
}

function resolveSecret(value?: string, filePath?: string): string | null {
    if (value && value.trim() !== '') {
        return value;
    }

    if (!filePath) {
        return null;
    }

    const fileValue = readFileSync(filePath, 'utf8').trim();

    return fileValue === '' ? null : fileValue;
}
