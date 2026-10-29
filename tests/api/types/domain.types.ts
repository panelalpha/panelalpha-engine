export interface DomainDetails {
  document_root: string;
  aliases?: string[];
}

export interface Domain {
  domain: string;
  type: 'main' | 'addon' | 'alias';
  details?: DomainDetails;
}

export interface CreateDomainRequest {
  domain: string;
  type: 'addon' | 'sub';
  parent_domain?: string | null;
  no_ssl?: boolean | null;
  aliases?: string[] | null;
}

export interface UpdateDomainRequest {
  document_root?: string;
  redirect_enabled?: boolean;
  force_https_redirect?: boolean;
  redirect_url?: string | null;
  aliases?: string[] | null;
}

export interface SslCertificate {
  certificate?: string;
  cabundle?: string;
  common_name: string;
  issuer_name: string;
  issuer_common_name?: string;
  not_before?: string | number;
  not_after?: string | number;
  domains?: string[];
}

export type SslCheckStatus =
  | 'trusted'
  | 'self_signed'
  | 'untrusted_issuer'
  | 'expired'
  | 'not_yet_valid'
  | 'domain_mismatch'
  | 'unreadable'
  | 'not_resolving'
  | 'refused_address'
  | 'unreachable';

export interface SslCheckName {
  common_name: string | null;
  organization: string | null;
  country: string | null;
}

export interface SslCheckCertificate {
  status: SslCheckStatus;
  issuer?: string;
  common_name?: string;
  names?: string[];
  self_signed?: boolean;
  chain_trusted?: boolean | null;
  covers_domain?: boolean;
  valid_from?: string | null;
  expires_at?: string | null;
  days_remaining?: number | null;
  signer?: SslCheckName | null;
  serial_number?: string | null;
  signature_algorithm?: string | null;
  key?: { type: string | null; bits: number | null; curve: string | null } | null;
  fingerprint_sha256: string | null;
  chain?: {
    subject: SslCheckName | null;
    signer: SslCheckName | null;
    expires_at: string | null;
  }[];
}

export interface SslCheckProbe {
  address: string;
  answered: boolean;
  refused: boolean;
  error: string | null;
  certificate: SslCheckCertificate | null;
  serves_installed?: boolean | null;
}

export interface SslCheck {
  domain: string;
  status: SslCheckStatus;
  checked_at: string;
  issuer: string | null;
  signer: SslCheckName | null;
  valid_from: string | null;
  expires_at: string | null;
  days_remaining: number | null;
  dns: { addresses: string[]; expected: string[]; points_here: boolean };
  public: SslCheckProbe | null;
  engine: SslCheckProbe | null;
}

export interface LogFile {
  file: string;
  path?: string;
  size?: number;
  /** Last-modified timestamp; the engine may expose it as `modified` or `mtime`. */
  modified?: string;
  mtime?: string | number;
}
