export type CdnSecurityLevel =
  'off' | 'essentially_off' | 'low' | 'medium' | 'high' | 'under_attack';

export interface CdnStatus {
  provider: 'cloudflare';
  zone_id: string;
  zone_name: string;
  proxied: boolean;
  security_level: CdnSecurityLevel;
  development_mode: boolean;
}

export interface CdnPurgeRequest {
  everything?: boolean;
  files?: string[];
}

export interface CdnPurgeResult {
  purged: boolean;
}

export interface CdnSecurityLevelRequest {
  level: CdnSecurityLevel;
}

export interface CdnSecurityLevelResult {
  security_level: CdnSecurityLevel;
}
