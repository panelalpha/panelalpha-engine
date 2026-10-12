/**
 * A deploy source that is only a compose file: the service runs a published
 * image whose tag comes from a project env var, and keeps a named volume.
 * Nothing is built. A panel shipped as an image is deployed exactly so, and
 * updated by changing the tag.
 *
 * The page says which nginx the volume first saw and which one runs now, so
 * one read proves both that the new tag was pulled and that the volume
 * survived it.
 */
export const IMAGE_TAG_VAR = 'IMAGE_TAG';

export const FIRST_TAG = '1.27-alpine';

export const NEXT_TAG = '1.28-alpine';

export const PAGE_TITLE = 'pae-image-only';

export const IMAGE_ONLY_COMPOSE = `services:
  app:
    image: nginx:\${${IMAGE_TAG_VAR}:-${FIRST_TAG}}
    ports:
      - "80:80"
    volumes:
      - data:/data
    command:
      - sh
      - -c
      - |
        [ -f /data/first ] || nginx -v 2> /data/first
        nginx -v 2> /tmp/now
        printf '<!doctype html><title>${PAGE_TITLE}</title><p>first: %s</p><p>now: %s</p>\\n' "$$(cat /data/first)" "$$(cat /tmp/now)" > /usr/share/nginx/html/index.html
        exec nginx -g 'daemon off;'
volumes:
  data:
`;

/** `nginx version: nginx/1.27.5` from the page, for the line it is on. */
export function nginxVersions(page: string): { first: string | null; now: string | null } {
  return {
    first: /first: nginx version: nginx\/(\S+?)</.exec(page)?.[1] ?? null,
    now: /now: nginx version: nginx\/(\S+?)</.exec(page)?.[1] ?? null,
  };
}
