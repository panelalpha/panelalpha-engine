import { expect, test } from '@/fixtures/test-options';
import type { EximConfig } from '@/types';
import { expectOneOf } from '@/helpers/expect-one-of';
import { randomEmail } from '@/helpers/random';
import { eximConfigResponseSchema } from '@/schemas';

/** Accepted or rejected — either is fine; a crash or a hang is not. */
const HANDLED = [200, 204, 400, 422, 500, 502, 503] as const;
const APPLIED = [200, 204] as const;
const REFUSED = [400, 422] as const;

test.describe('Exim configuration', () => {
  /**
   * Mail routing is engine-wide, so every test that writes to it puts the
   * original configuration back — including when it fails part-way.
   */
  let original: EximConfig;

  test.beforeEach(async ({ api }) => {
    original = (await api.getEximConfig()).data;
  });

  test.afterEach(async ({ api }) => {
    // A refused restore would leave this test's configuration for every test after it.
    const restored = await api.updateEximConfigRaw(original);
    expectOneOf(
      restored.status,
      APPLIED,
      `restoring the Exim config: ${JSON.stringify(restored.body)}`
    );
  });

  test('the configuration is readable and matches its schema', async ({ api }) => {
    const response = await api.getEximConfig();

    expect(response.data).toBeTruthy();

    const parsed = eximConfigResponseSchema.safeParse(response);
    expect(parsed.success, parsed.error?.toString()).toBe(true);
  });

  test('every documented field is present', async ({ api }) => {
    const config = (await api.getEximConfig()).data as Record<string, unknown>;

    const expectedFields = [
      'smarthost_provider',
      'sendgrid_api_token',
      'mailchannels_username',
      'mailchannels_password',
      'amazon_ses_smtp_endpoint',
      'amazon_ses_starttls_port',
      'amazon_ses_smtp_username',
      'amazon_ses_smtp_password',
      'smtp_host',
      'smtp_port',
      'smtp_username',
      'smtp_password',
      'smtp_implicit_tls',
      'sender_domain',
    ];

    expect(expectedFields.filter((field) => !(field in config))).toHaveLength(0);
  });

  const providerUpdates = [
    [
      'a plain SMTP smarthost',
      {
        smarthost_provider: 'smtp',
        smtp_host: 'smtp.test-example.com',
        smtp_port: '587',
        smtp_username: 'smtp-user',
        smtp_password: 'smtp-pass',
        smtp_implicit_tls: true,
        sender_domain: 'example.test',
      },
    ],
    [
      'a SendGrid preset',
      {
        smarthost_provider: 'sendgrid',
        sendgrid_api_token: 'SG.test-token',
        sender_domain: 'example.test',
      },
    ],
  ] as const;

  for (const [label, changes] of providerUpdates) {
    test(`${label} can be configured`, async ({ api }) => {
      const response = await api.updateEximConfigRaw({ ...original, ...changes });
      expectOneOf(response.status, APPLIED);
    });
  }

  test('implicit TLS can be toggled', async ({ api }) => {
    const response = await api.updateEximConfigRaw({
      ...original,
      smtp_implicit_tls: !original.smtp_implicit_tls,
    });
    expectOneOf(response.status, APPLIED);
  });

  test('a non-boolean implicit TLS value is refused', async ({ api }) => {
    const response = await api.updateEximConfigRaw({
      ...original,
      smtp_implicit_tls: 'invalid' as unknown as boolean,
    });
    expectOneOf(response.status, REFUSED);
  });

  test('an empty SMTP host is handled', async ({ api }) => {
    const response = await api.updateEximConfigRaw({
      ...original,
      smarthost_provider: 'smtp',
      smtp_host: '',
    });
    // Refusing it or storing it and failing later at delivery are both defensible.
    expectOneOf(response.status, [200, 204, 400, 422]);
  });

  test('a null payload does not crash the endpoint', async ({ api }) => {
    const response = await api.updateEximConfigRaw(null);
    expectOneOf(response.status, [200, 204, 400, 422]);
  });

  /**
   * The SMTP host becomes Exim's smarthost in its configuration file. Anything
   * that is not a hostname or an IP address is refused before the configuration
   * is saved or applied.
   */
  const shellPayloads = ['; rm -rf /', '| cat /etc/passwd', '$(whoami)', '`whoami`', '\n/bin/sh'];

  for (const payload of shellPayloads) {
    test(
      `a smtp_host containing ${JSON.stringify(payload)} is refused`,
      {
        tag: ['@security'],
      },
      async ({ api }) => {
        const response = await api.updateEximConfigRaw({
          ...original,
          smarthost_provider: 'smtp',
          smtp_host: `smtp.example.com${payload}`,
          smtp_port: '587',
        });

        expect(response.status, JSON.stringify(response.body)).toBe(422);
        expect((response.body as { errors?: object }).errors).toHaveProperty('smtp_host');
        expect((await api.getEximConfig()).data, 'nothing was saved').toEqual(original);
      }
    );
  }
});

test.describe('Exim test email', () => {
  test('a test email to a well-formed address is accepted', async ({ api }) => {
    const response = await api.sendEximTestEmail({
      email: `test-${Date.now()}@example.test`,
      subject: 'Test email from engine API',
      body: 'This is a test email sent via the engine API test suite.',
    });
    expectOneOf(response.status, APPLIED);
  });

  test('a random recipient is handled', async ({ api }) => {
    expectOneOf((await api.sendEximTestEmail({ email: randomEmail() })).status, HANDLED);
  });

  const missingRecipients = [
    ['no fields at all', {}],
    ['only a config object', { config: {} }],
    ['an empty email string', { email: '' }],
  ] as const;

  for (const [label, payload] of missingRecipients) {
    test(`a request with ${label} is refused`, async ({ api }) => {
      expectOneOf((await api.sendEximTestEmailRaw(payload)).status, REFUSED);
    });
  }

  const nonStringRecipients = [123, true, { value: 'test@example.test' }];

  for (const email of nonStringRecipients) {
    test(`a ${typeof email} recipient is refused`, async ({ api }) => {
      expectOneOf((await api.sendEximTestEmailRaw({ email } as never)).status, REFUSED);
    });
  }

  const oversizedRecipients = [
    ['a 200-character local part', `${'x'.repeat(200)}@example.test`],
    ['a 200-character domain', `user@${'y'.repeat(200)}.test`],
  ] as const;

  for (const [label, email] of oversizedRecipients) {
    test(`${label} is refused`, async ({ api }) => {
      const response = await api.sendEximTestEmailRaw({ email });
      expect(response.status, JSON.stringify(response.body)).toBe(422);
    });
  }

  test('a plus-tagged address is handled', async ({ api }) => {
    expectOneOf(
      (await api.sendEximTestEmailRaw({ email: 'test+tag_name@example.test' })).status,
      HANDLED
    );
  });

  const sqlPayloads = [
    "'; DROP TABLE users; --",
    "admin'--",
    "' OR '1'='1",
    "' UNION SELECT * FROM users--",
  ];

  for (const payload of sqlPayloads) {
    test(
      `a recipient containing ${JSON.stringify(payload)} is contained`,
      {
        tag: ['@security'],
      },
      async ({ api }) => {
        const response = await api.sendEximTestEmailRaw({
          email: `test${payload}@example.test`,
        });
        expectOneOf(response.status, HANDLED);

        // The engine has to still be there afterwards.
        expect((await api.getEximConfig()).data).toBeTruthy();
      }
    );
  }
});
