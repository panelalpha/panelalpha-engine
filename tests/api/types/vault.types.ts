/** A vault entry's lifecycle, as the engine reports it. */
export type VaultSecretStatus = 'pending' | 'filled' | 'expired';

/**
 * What `POST /vault/secrets` returns — the only response that carries the
 * reference in the clear, and the only one that carries the form URL.
 */
export interface CreatedVaultSecret {
  /** `vault:<id>`; pass it where the secret would go. */
  ref: string;
  type: string;
  /** The page the customer opens to paste the secret in. */
  url: string;
  status: VaultSecretStatus;
  /** Lifetime in seconds from creation. */
  expires_in: number;
}

/**
 * A listed or fetched entry.
 *
 * `ref` is always null here: only the reference's hash is stored, so no read
 * path can hand it back. The secret itself never appears in any shape.
 */
export interface VaultSecretEntry {
  ref: null;
  type: string;
  status: VaultSecretStatus;
  used_count: number;
  last_used_at: string | null;
  created_at: string | null;
  expires_at: string;
}

export interface CreateVaultSecretRequest {
  type: string;
}

export interface DeletedVaultSecret {
  ref: string;
  deleted: boolean;
}
