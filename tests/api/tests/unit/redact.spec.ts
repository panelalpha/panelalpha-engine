import { expect, test } from '@/fixtures/test-options';
import { redactAndTruncate, redactText, truncateText } from '@/helpers/redact';

const SECRET_ENV = {
  API_TOKEN: 'tok_PLANTEDAPITOKEN1234',
  LICENSE_KEY: 'LIC-PLANTED-0123456789',
  SYSTEM_UPDATE_LICENSE_KEY: 'SUL-PLANTED-0123456789',
  DB_PASSWORD: 'PlantedDbPassw0rd',
  CUSTOM_SERVICE_KEY: 'PlantedServiceKey99',
};

test.describe('redactText', () => {
  const saved: Record<string, string | undefined> = {};

  test.beforeEach(() => {
    for (const [name, value] of Object.entries(SECRET_ENV)) {
      saved[name] = process.env[name];
      process.env[name] = value;
    }
  });

  test.afterEach(() => {
    for (const name of Object.keys(SECRET_ENV)) {
      if (saved[name] === undefined) {
        delete process.env[name];
      } else {
        process.env[name] = saved[name];
      }
    }
  });

  test('env secrets never survive, wherever they appear', () => {
    const text = Object.values(SECRET_ENV)
      .map((value) => `x ${value} y /path/${value}/z`)
      .join('\n');
    const out = redactText(text);
    for (const value of Object.values(SECRET_ENV)) {
      expect(out).not.toContain(value);
    }
    expect(out).toContain('[REDACTED]');
  });

  test('extra secrets are replaced too, short ones are left alone', () => {
    expect(redactText('a CustomSecretValue b', ['CustomSecretValue'])).toBe('a [REDACTED] b');
    expect(redactText('a abc b', ['abc'])).toBe('a abc b');
  });

  test('Bearer and Authorization values', () => {
    expect(redactText('curl -H "Authorization: Bearer abc.DEF-123"')).toBe(
      'curl -H "Authorization: [REDACTED]"'
    );
    expect(redactText('got Bearer abc.DEF-123 back')).toBe('got Bearer [REDACTED] back');
    expect(redactText('Authorization=Basic dXNlcjpwYXNz')).toBe('Authorization=[REDACTED]');
  });

  test('credentials in URLs', () => {
    expect(redactText('clone https://deploy:s3cr3tvalue@git.example.com/a.git')).toBe(
      'clone https://deploy:[REDACTED]@git.example.com/a.git'
    );
    expect(redactText('mysql://root:pw@db:3306/x')).toBe('mysql://root:[REDACTED]@db:3306/x');
  });

  test('JSON, query and key=value pairs', () => {
    expect(redactText('{"password":"hunter2 x","name":"ok"}')).toBe(
      '{"password":"[REDACTED]","name":"ok"}'
    );
    expect(redactText('{"log":"{\\"api_key\\":\\"abc123\\"}"}')).toBe(
      '{"log":"{\\"api_key\\":\\"[REDACTED]\\"}"}'
    );
    expect(redactText('GET /x?token=abc123&page=2')).toBe('GET /x?token=[REDACTED]&page=2');
    expect(redactText('DB_PASSWORD=abc123 HOST=db')).toBe('DB_PASSWORD=[REDACTED] HOST=db');
    expect(redactText("secret: 'abc 123'")).toBe("secret: '[REDACTED]'");
    expect(redactText('private_key = abc\nsso_secret: def')).toBe(
      'private_key = [REDACTED]\nsso_secret: [REDACTED]'
    );
    expect(redactText('apikey=abc')).toBe('apikey=[REDACTED]');
  });

  test('leaves ordinary text alone', () => {
    const text = 'Container pwtest-web started; processor=x86 classes loaded';
    expect(redactText(text)).toBe(text);
  });

  test('never throws on odd input', () => {
    expect(() => redactText(undefined as unknown as string)).not.toThrow();
    expect(() => redactText('\\'.repeat(5000) + '"password":"' + '"'.repeat(5000))).not.toThrow();
  });
});

test.describe('truncateText', () => {
  test('keeps the end and says how much was cut', () => {
    expect(truncateText('0123456789', 4)).toBe('…[6 chars cut]\n6789');
  });

  test('short text is returned as it is', () => {
    expect(truncateText('abc', 10)).toBe('abc');
  });

  test('redaction happens before the cut', () => {
    const secret = 'LongSecretValue123';
    const out = redactAndTruncate(`${'a'.repeat(20)} ${secret}`, 30, [secret]);
    expect(out).not.toContain('Secret');
    expect(out.endsWith('[REDACTED]')).toBe(true);
  });
});
