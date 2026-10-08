import { registeredSecrets } from './secret-registry';
import { REDACTED, usableSecrets } from './secret-values';

// Text that reaches the console, an error message, JUnit or an attachment is written as it
// is, so secrets are removed here first. Trace archives are rewritten separately after the run.

/** Env vars that always hold a secret, whatever their length-based name check says. */
const KNOWN_SECRET_ENV = ['API_TOKEN', 'LICENSE_KEY', 'SYSTEM_UPDATE_LICENSE_KEY'];

const SECRET_ENV_NAME = /(TOKEN|PASSWORD|PASSWD|SECRET|_KEY)$/i;

const SECRET_KEY = '(?:password|passwd|secret|token|api_?key|private_?key|(?<![a-z])sso)';

/** `"password":"x"`, `password: 'x y'`. */
const QUOTED_PAIR = new RegExp(
  `([\\w.-]*${SECRET_KEY}[\\w.-]*["']?\\s*[:=]\\s*)(["'])(?:(?!\\2)[^\\\\\\r\\n]|\\\\.)*\\2`,
  'gi'
);
/** The same pair inside a JSON string: `\"password\":\"x\"`. */
const ESCAPED_PAIR = new RegExp(
  `([\\w.-]*${SECRET_KEY}[\\w.-]*\\\\"\\s*[:=]\\s*\\\\")[^"\\\\]*`,
  'gi'
);
const BARE_PAIR = new RegExp(
  `([\\w.-]*${SECRET_KEY}[\\w.-]*\\\\?["']?\\s*[:=]\\s*)(?!\\\\?["'])[^\\s"'\\\\,&;}]+`,
  'gi'
);
const AUTHORIZATION = /(Authorization["']?\s*[:=]\s*["']?)[^\r\n"',]+/gi;
const BEARER = /\b(Bearer\s+)[A-Za-z0-9._~+/=-]+/gi;
const URL_CREDENTIALS = /(\b[a-z][a-z0-9+.-]*:\/\/[^\s:/@]+:)[^\s@/]+(@)/gi;

/** Values of the env vars that hold credentials. */
export function envSecrets(env: NodeJS.ProcessEnv = process.env): string[] {
  const values = Object.entries(env)
    .filter(([name]) => KNOWN_SECRET_ENV.includes(name) || SECRET_ENV_NAME.test(name))
    .map(([, value]) => value);
  return usableSecrets(values).sort((a, b) => b.length - a.length);
}

/** The secret as written in plain text, inside a JSON string and inside a URL. */
function encodedForms(secret: string): string[] {
  return [...new Set([secret, JSON.stringify(secret).slice(1, -1), encodeURIComponent(secret)])];
}

/**
 * `text` with credentials replaced, except that a JSON document (or JSON lines) which parsed
 * before is left parseable: a line the patterns would break is redacted through its parsed structure instead.
 */
export function redactStructuredText(text: string, extraSecrets: readonly string[] = []): string {
  const redacted = redactText(text, extraSecrets);
  if (redacted === text || (!isJson(text) && !text.includes('\n'))) {
    return redacted;
  }
  if (isJson(text)) {
    return isJson(redacted) ? redacted : redactJson(text, extraSecrets);
  }
  const before = text.split('\n');
  const after = redacted.split('\n');
  if (before.length !== after.length) {
    return redacted;
  }
  return after
    .map((line, i) =>
      isJson(before[i]) && !isJson(line) ? redactJson(before[i], extraSecrets) : line
    )
    .join('\n');
}

function isJson(text: string): boolean {
  const trimmed = text.trim();
  if (!trimmed.startsWith('{') && !trimmed.startsWith('[')) {
    return false;
  }
  try {
    JSON.parse(trimmed);
    return true;
  } catch {
    return false;
  }
}

/** Parses the JSON, redacts every string and every value under a secret-looking key. */
function redactJson(text: string, extraSecrets: readonly string[]): string {
  const keyPattern = new RegExp(SECRET_KEY, 'i');
  const walk = (value: unknown, secretKey: boolean): unknown => {
    if (Array.isArray(value)) {
      return value.map((item) => walk(item, secretKey));
    }
    if (value !== null && typeof value === 'object') {
      return Object.fromEntries(
        Object.entries(value).map(([key, item]) => [key, walk(item, keyPattern.test(key))])
      );
    }
    if (value === null || value === undefined || value === '') {
      return value;
    }
    return secretKey
      ? REDACTED
      : typeof value === 'string'
        ? redactText(value, extraSecrets)
        : value;
  };
  return JSON.stringify(walk(JSON.parse(text), false));
}

/** `text` with credentials replaced; never throws. */
export function redactText(text: string, extraSecrets: readonly string[] = []): string {
  try {
    let out = String(text);
    const secrets = usableSecrets([...envSecrets(), ...registeredSecrets(), ...extraSecrets]);
    for (const secret of secrets.sort((a, b) => b.length - a.length).flatMap(encodedForms)) {
      out = out.split(secret).join(REDACTED);
    }
    return out
      .replace(URL_CREDENTIALS, `$1${REDACTED}$2`)
      .replace(AUTHORIZATION, `$1${REDACTED}`)
      .replace(BEARER, `$1${REDACTED}`)
      .replace(ESCAPED_PAIR, `$1${REDACTED}`)
      .replace(QUOTED_PAIR, `$1$2${REDACTED}$2`)
      .replace(BARE_PAIR, `$1${REDACTED}`);
  } catch {
    return '[text withheld: redaction failed]';
  }
}

/** The last `maxChars` of `text`, behind a marker saying how much was cut. */
export function truncateText(text: string, maxChars: number): string {
  try {
    const limit = Math.max(0, Math.floor(maxChars));
    if (text.length <= limit) {
      return text;
    }
    return `…[${text.length - limit} chars cut]\n${text.slice(text.length - limit)}`;
  } catch {
    return '';
  }
}

/** Redacts before trimming, so a secret cut in half is never left behind. */
export function redactAndTruncate(
  text: string,
  maxChars: number,
  extraSecrets: readonly string[] = []
): string {
  return truncateText(redactText(text, extraSecrets), maxChars);
}
