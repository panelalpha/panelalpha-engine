/** A vault entry's lifecycle, as the engine reports it. */
export type VaultSecretStatus = 'pending' | 'filled' | 'abandoned' | 'expired';

/** `project`: usable by the first project given it. `global`: by any project that names it. */
export type VaultSecretScope = 'project' | 'global';

/**
 * A listed or fetched entry. The secret itself never appears in any shape,
 * and neither does the paste URL's token.
 */
export interface VaultSecretEntry {
  id: number;
  /** `vault:<id>`; pass it where the secret would go. */
  ref: string;
  type: string;
  scope: VaultSecretScope;
  /** The project a `project` entry belongs to; null until one claims it. */
  project: string | null;
  purpose: string | null;
  status: VaultSecretStatus;
  used_count: number;
  last_used_at: string | null;
  created_at: string | null;
  /** When the secret expires and is deleted; null when it never does. */
  expires_at: string | null;
  /** When the paste form closes. */
  url_expires_at: string | null;
}

/** What `POST /vault/secrets` returns: the entry plus its paste form. */
export interface CreatedVaultSecret extends VaultSecretEntry {
  /** The page the customer opens to paste the secret in. */
  url: string;
  /** Seconds the paste form stays open. */
  url_expires_in: number;
}

export interface CreateVaultSecretRequest {
  type: string;
  scope?: VaultSecretScope;
  purpose?: string;
  /** Project scope only: the project that owns it from the start. */
  project?: string;
  /** Seconds until the secret expires and is deleted; omitted, it never does. */
  expires_in?: number;
}

export interface DeletedVaultSecret {
  ref: string;
  deleted: boolean;
}
