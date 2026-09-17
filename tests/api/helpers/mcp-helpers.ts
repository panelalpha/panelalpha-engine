import { expect } from '@playwright/test';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';
import { McpHttpClient, parseMcpJsonRpc, type McpJsonRpcRequest } from '@/clients/mcp-http';
import type { APIRequestContext } from '@playwright/test';

const suiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/**
 * The engine's tool-name map, `core/app/Mcp/tool-names.php`.
 *
 * Read from the repository rather than copied into the suite: the map is the
 * client-facing contract ("VERB /path" -> tool name), and a second hand-kept
 * list would drift from it exactly when a parity test needs to be trusted.
 */
const CATALOGUE_FILE = path.resolve(suiteRoot, '../../core/app/Mcp/tool-names.php');

/** Tools written by hand rather than derived from an API route. */
export const HAND_WRITTEN_MCP_TOOLS = ['metrics_latest', 'project_list_summary'] as const;

export interface McpCatalogueEntry {
  /** HTTP verb of the API operation the tool wraps. */
  verb: string;
  /** API path with `{placeholders}`, e.g. `/projects/{username}/domains`. */
  apiPath: string;
  /** The MCP tool name, e.g. `domain_list`. */
  tool: string;
  /** A GET is read-only; the engine's ToolPolicy treats it that way too. */
  readOnly: boolean;
  /** Placeholder names in `apiPath`, in order. */
  params: string[];
}

/**
 * Parses the tool-name map, or returns null when the suite runs outside an
 * engine checkout (npm package copied elsewhere, CI artifact without `core/`).
 */
export function readMcpToolCatalogue(): McpCatalogueEntry[] | null {
  if (!fs.existsSync(CATALOGUE_FILE)) {
    return null;
  }
  const source = fs.readFileSync(CATALOGUE_FILE, 'utf8');
  const entries: McpCatalogueEntry[] = [];
  const pattern = /'([A-Z]+) (\/[^']*)'\s*=>\s*'([a-z0-9_]+)'/g;
  let match: RegExpExecArray | null;
  while ((match = pattern.exec(source)) !== null) {
    const [, verb, apiPath, tool] = match;
    entries.push({
      verb,
      apiPath,
      tool,
      readOnly: verb === 'GET',
      params: [...apiPath.matchAll(/\{([a-zA-Z]+)\}/g)].map((m) => m[1]),
    });
  }
  return entries.length > 0 ? entries : null;
}

export interface McpToolDescriptor {
  name: string;
  description?: string;
  inputSchema?: Record<string, unknown>;
}

/**
 * One initialized MCP session over streamable HTTP.
 *
 * `tools/call` answers 200 with `result.isError: true` when the tool itself
 * fails, so a test that only checks the HTTP status — or only checks the
 * JSON-RPC `error` member — passes against a tool that is broken. Everything
 * here goes through {@link expectMcpToolOk}, which looks at all three.
 */
export class McpSession {
  private nextId = 1;

  private constructor(
    private readonly client: McpHttpClient,
    private readonly token: string
  ) {}

  static async open(
    request: APIRequestContext,
    apiBaseUrl: string,
    token: string
  ): Promise<McpSession> {
    const session = new McpSession(new McpHttpClient(request, apiBaseUrl), token);
    const handshake = await session.send('initialize', {
      protocolVersion: '2025-06-18',
      capabilities: {},
      clientInfo: { name: 'engine-api-tests', version: '1' },
    });
    expect(
      handshake.error,
      `initialize failed: ${JSON.stringify(handshake.error)}`
    ).toBeUndefined();
    return session;
  }

  /** Every tool the server exposes, following `nextCursor` pagination. */
  async listTools(): Promise<McpToolDescriptor[]> {
    const tools: McpToolDescriptor[] = [];
    let cursor: string | undefined;
    do {
      const payload = await this.send('tools/list', cursor ? { cursor } : {});
      expect(payload.error, `tools/list failed: ${JSON.stringify(payload.error)}`).toBeUndefined();
      const result = (payload.result ?? {}) as { tools?: unknown; nextCursor?: unknown };
      const listed: unknown[] = Array.isArray(result.tools) ? (result.tools as unknown[]) : [];
      for (const tool of listed) {
        if (
          tool !== null &&
          typeof tool === 'object' &&
          typeof (tool as { name?: unknown }).name === 'string'
        ) {
          tools.push(tool as McpToolDescriptor);
        }
      }
      cursor = typeof result.nextCursor === 'string' ? result.nextCursor : undefined;
    } while (cursor);
    return tools;
  }

  /** Calls a tool and returns the JSON-RPC payload without judging it. */
  async call(name: string, args: Record<string, unknown> = {}): Promise<McpPayload> {
    return this.send('tools/call', { name, arguments: args });
  }

  /** Calls a tool and fails the test unless it succeeded. */
  async callOk(name: string, args: Record<string, unknown> = {}): Promise<McpPayload> {
    const payload = await this.call(name, args);
    expectMcpToolOk(payload, name);
    return payload;
  }

  private async send(method: string, params: Record<string, unknown>): Promise<McpPayload> {
    const body: McpJsonRpcRequest = { jsonrpc: '2.0', id: this.nextId++, method, params };
    const response = await this.client.send(body, this.token);
    expect(response.ok(), `${method} answered HTTP ${response.status()}`).toBe(true);
    const parsed = parseMcpJsonRpc(await response.text());
    expect(parsed, `${method} produced no JSON-RPC payload`).toBeTruthy();
    return parsed as McpPayload;
  }
}

export type McpPayload = Record<string, unknown> & {
  result?: unknown;
  error?: unknown;
};

interface McpToolResult {
  isError?: boolean;
  content?: unknown;
}

/** The text a tool returned, joined across content blocks — for failure messages. */
export function mcpResultText(payload: McpPayload): string {
  const result = payload.result;
  if (result === null || typeof result !== 'object') {
    return '';
  }
  const content = (result as McpToolResult).content;
  if (!Array.isArray(content)) {
    return '';
  }
  return content
    .map((block) =>
      block !== null &&
      typeof block === 'object' &&
      typeof (block as { text?: unknown }).text === 'string'
        ? (block as { text: string }).text
        : ''
    )
    .join('\n')
    .trim();
}

/** True when the server reported the tool itself as having failed. */
export function isMcpToolError(payload: McpPayload): boolean {
  if (payload.error !== undefined) {
    return true;
  }
  const result = payload.result;
  return (
    result !== null && typeof result === 'object' && (result as McpToolResult).isError === true
  );
}

/**
 * Asserts a tool call succeeded.
 *
 * Three ways a call can fail and only one of them is a JSON-RPC `error`: the
 * transport can refuse it, the protocol can reject it, or the tool can run and
 * report `isError` with the reason in its content. All three fail here, and the
 * message carries the text the tool returned.
 */
export function expectMcpToolOk(payload: McpPayload, name: string): void {
  expect(
    payload.error,
    `${name} returned a JSON-RPC error: ${JSON.stringify(payload.error)}`
  ).toBeUndefined();
  expect(payload.result, `${name} returned no result`).toBeTruthy();
  expect(
    (payload.result as McpToolResult).isError === true,
    `${name} reported a tool error: ${mcpResultText(payload) || '<no text>'}`
  ).toBe(false);
}

/** Asserts a tool call failed, as a bad argument or a missing record should. */
export function expectMcpToolError(payload: McpPayload, name: string): void {
  expect(
    isMcpToolError(payload),
    `${name} was expected to fail but answered: ${mcpResultText(payload) || JSON.stringify(payload.result)}`
  ).toBe(true);
}
