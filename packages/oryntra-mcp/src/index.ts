import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { loadConfig } from './config.js';
import { createServer } from './server.js';

async function main(): Promise<void> {
    const keepAlive = setInterval(() => {
        // Keep the stdio MCP process alive while waiting for client traffic.
    }, 60_000);

    const config = loadConfig();
    const server = createServer(config);
    const transport = new StdioServerTransport();

    process.stdin.resume();
    process.stdin.once('data', () => {
        clearInterval(keepAlive);
    });
    process.stdin.once('end', () => {
        clearInterval(keepAlive);
    });
    transport.onclose = () => {
        clearInterval(keepAlive);
    };

    await server.connect(transport);
}

process.on('uncaughtException', (error) => {
    console.error(error);
    process.exitCode = 1;
});

process.on('unhandledRejection', (error) => {
    console.error(error);
    process.exitCode = 1;
});

void main();
