export interface EnvVarEntry {
  key: string;
  /** Null unless the read asked for `reveal=1`. */
  value: string | null;
  masked: boolean;
}

export interface EnvVarsView {
  vars: EnvVarEntry[];
  /** The keys the last deploy merged into the application environment. */
  applied_keys: string[];
}

export interface EnvVarsUpdateRequest {
  /** An empty or null value removes that key. */
  env_vars: Record<string, string | null>;
  /** True replaces the stored map instead of merging onto it. */
  replace?: boolean;
  apply?: 'none' | 'recreate';
}

export interface EnvVarsStoreResult {
  keys: string[];
}
