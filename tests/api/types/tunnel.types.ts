export type TunnelProvider = 'cloudflare' | 'panelalpha';

export type TunnelPurpose = 'default' | 'preview';

export interface Tunnel {
  id: number;
  hostname: string;
  provider: TunnelProvider;
  purpose?: TunnelPurpose;
  domain?: string | null;
  url?: string;
  valid_until?: string | null;
  details?: Record<string, unknown>;
  created_at?: string;
  updated_at?: string;
}

export interface CreateTunnelRequest {
  hostname?: string;
  provider?: TunnelProvider;
  purpose?: TunnelPurpose;
  ttl_minutes?: number;
}
