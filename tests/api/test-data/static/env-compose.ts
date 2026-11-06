/**
 * One published nginx image that writes the value of a project env var into its page at
 * start, so the page tells which value the running container was given.
 */
export const ENV_KEY = 'PA_TEST_VALUE';

export const ENV_COMPOSE = `services:
  web:
    image: nginx:1.27-alpine
    ports:
      - "80:80"
    environment:
      ${ENV_KEY}: \${${ENV_KEY}:-unset}
    command:
      - sh
      - -c
      - |
        printf '<!doctype html><title>env</title><p id="value">%s</p>\\n' "$$${ENV_KEY}" > /usr/share/nginx/html/index.html
        exec nginx -g 'daemon off;'
`;

export function servedValue(body: string): string | null {
  return /<p id="value">([^<]*)<\/p>/.exec(body)?.[1] ?? null;
}
