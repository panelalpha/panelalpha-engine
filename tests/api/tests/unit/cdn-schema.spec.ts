import { expect, test } from '@/fixtures/test-options';
import { cdnPurgeResponseSchema, cdnStatusSchema } from '@/schemas';

test.describe('CDN schemas', () => {
  const status = {
    provider: 'cloudflare',
    zone_id: '023e105f4ecef8ad9ca31a8372d0c353',
    zone_name: 'example.com',
    proxied: true,
    security_level: 'medium',
    development_mode: false,
  };

  test('accepts the documented status shape', () => {
    expect(cdnStatusSchema.parse(status).security_level).toBe('medium');
  });

  test('rejects a security level Cloudflare does not use', () => {
    expect(() => cdnStatusSchema.parse({ ...status, security_level: 'paranoid' })).toThrow();
  });

  test('accepts the purge envelope', () => {
    expect(cdnPurgeResponseSchema.parse({ data: { purged: true } }).data.purged).toBe(true);
  });
});
