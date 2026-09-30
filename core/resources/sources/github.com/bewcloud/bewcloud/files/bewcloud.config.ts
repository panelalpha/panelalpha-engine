import { Config, PartialDeep } from './lib/types.ts';

// Upstream's bewcloud.config.sample.ts with baseUrl taken from the account's
// public address (PA_PUBLIC_URL, set by the engine); session cookies need it.
const config: PartialDeep<Config> = {
  auth: {
    baseUrl: Deno.env.get('PA_PUBLIC_URL') || 'http://localhost:8000',
    allowSignups: false,
    enableEmailVerification: false,
    enableForeverSignup: true,
    enableMultiFactor: false,
  },
};

export default config;
