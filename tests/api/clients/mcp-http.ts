import type { APIRequestContext, APIResponse } from '@playwright/test';

export interface McpJsonRpcRequest {
  jsonrpc: '2.0';
  id: number;
  method: string;
  params?: Record<string, unknown>;
}

function mcpOrigin(apiBaseUrl: string): string {
  const url = new URL(apiBaseUrl);
  return `${url.protocol}//${url.host}`;
}

/**
 * Streamable HTTP client for `/mcp` and `/mcp/check` — not the REST
 * `/api/mcp-tokens` surface.
 */
export class McpHttpClient {
  readonly origin: string;

  constructor(
    private readonly request: APIRequestContext,
    apiBaseUrl: string
  ) {
    this.origin = mcpOrigin(apiBaseUrl);
  }

  async check(token?: string): Promise<APIResponse> {
    return this.request.get(`${this.origin}/mcp/check`, {
      headers: this.headers(token),
    });
  }

  async send(body: McpJsonRpcRequest, token: string): Promise<APIResponse> {
    return this.request.post(`${this.origin}/mcp`, {
      headers: {
        ...this.headers(token),
        Accept: 'application/json, text/event-stream',
        'Content-Type': 'application/json',
      },
      data: body,
    });
  }

  async methodNotAllowed(method: 'get' | 'delete', token: string): Promise<APIResponse> {
    const url = `${this.origin}/mcp`;
    const headers = this.headers(token);
    return method === 'get'
      ? this.request.get(url, { headers })
      : this.request.delete(url, { headers });
  }

  private headers(token?: string): Record<string, string> {
    return token ? { Authorization: `Bearer ${token}` } : {};
  }
}

export function parseMcpJsonRpc(body: string): Record<string, unknown> | null {
  // An SSE body can carry notifications ahead of the reply (execute_tools
  // always answers as a stream); the reply is the last message with a result
  // or an error.
  const events = body
    .split(/\r?\n/)
    .filter((line) => line.startsWith('data: '))
    .map((line) => decode(line.slice(6)))
    .filter((message) => message !== null && ('result' in message || 'error' in message));
  if (events.length > 0) {
    return events[events.length - 1];
  }
  return decode(body);
}

function decode(text: string): Record<string, unknown> | null {
  try {
    const decoded: unknown = JSON.parse(text);
    return decoded !== null && typeof decoded === 'object'
      ? (decoded as Record<string, unknown>)
      : null;
  } catch {
    return null;
  }
}
