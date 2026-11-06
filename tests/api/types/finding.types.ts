/** One form of an action that resolves a finding. A null value is the caller's to supply. */
export interface Hint {
  type: 'rest' | 'mcp' | 'cli';
  action: string;
  method?: string;
  path?: string;
  path_params?: Record<string, unknown>;
  body?: Record<string, unknown>;
  query?: Record<string, unknown>;
  tool?: string;
  arguments?: Record<string, unknown>;
  command?: string;
}

/** What a deploy or an inspection found: a stable code, a sentence naming no surface, its facts and its hints. */
export interface Finding {
  code: string;
  message: string;
  hints: Hint[];
  [fact: string]: unknown;
}
