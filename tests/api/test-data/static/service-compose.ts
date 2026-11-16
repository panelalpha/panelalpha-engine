/**
 * One nginx service from a published image, nothing built. It prints a start line with a
 * counter kept in a volume, so a log read tells how many times the container started.
 */
export const SERVICE = 'web';

export const START_LINE = 'pa-service-start';

export function serviceCompose(marker: string): string {
  return `services:
  ${SERVICE}:
    image: nginx:1.27-alpine
    ports:
      - "80:80"
    volumes:
      - state:/state
    command:
      - sh
      - -c
      - |
        n=$$(( $$(cat /state/starts 2>/dev/null || echo 0) + 1 ))
        echo $$n > /state/starts
        echo "${START_LINE} $$n"
        printf '<!doctype html><title>${marker}</title><p>${marker}</p>\\n' > /usr/share/nginx/html/index.html
        exec nginx -g 'daemon off;'
volumes:
  state:
`;
}

/** The highest start counter in the log lines, 0 when there is none. */
export function startsIn(lines: string[]): number {
  let highest = 0;
  for (const line of lines) {
    const match = new RegExp(`${START_LINE} (\\d+)`).exec(line);
    if (match) {
      highest = Math.max(highest, Number(match[1]));
    }
  }
  return highest;
}
