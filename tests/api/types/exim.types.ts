export interface EximConfig {
  smarthost_provider?: string;
  sendgrid_api_token?: string | null;
  sendgrid_api_token_set?: boolean;
  mailchannels_username?: string;
  mailchannels_password?: string | null;
  mailchannels_password_set?: boolean;
  amazon_ses_smtp_endpoint?: string;
  amazon_ses_starttls_port?: string;
  amazon_ses_smtp_username?: string;
  amazon_ses_smtp_password?: string | null;
  amazon_ses_smtp_password_set?: boolean;
  smtp_host?: string;
  smtp_port?: string;
  smtp_username?: string;
  smtp_password?: string | null;
  smtp_password_set?: boolean;
  smtp_implicit_tls?: boolean | string;
  sender_domain?: string;
  [key: string]: unknown;
}

export interface EximTestEmailRequest {
  email: string;
  config?: Partial<EximConfig>;
  subject?: string;
  body?: string;
}
