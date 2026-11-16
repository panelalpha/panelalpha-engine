import type { SiteFileMap } from '@/helpers/site-files';

/** A Dockerfile app serving its version; the page is its last layer, so rebuilds reuse the rest. */
const VERSION_META = /<meta name="pa-version" content="([^"]+)">/;

const DOCKERFILE = `FROM nginx:1.27-alpine
RUN echo "built $(date -u +%s)" > /usr/share/nginx/html/built.txt && sleep 2
COPY index.html /usr/share/nginx/html/index.html
`;

function page(version: string): string {
  return `<!doctype html><html><head><meta name="pa-version" content="${version}"><title>${version}</title></head><body><p>${version}</p></body></html>\n`;
}

export function versionedApp(version: string): SiteFileMap {
  return { Dockerfile: DOCKERFILE, 'index.html': page(version) };
}

/** Builds like {@link versionedApp}, then its container exits at once, so it never answers. */
export function exitingApp(version: string): SiteFileMap {
  return {
    Dockerfile: `${DOCKERFILE}CMD ["sh", "-c", "echo 'pa-test: this version exits'; exit 1"]\n`,
    'index.html': page(version),
  };
}

export function versionOf(body: string): string | null {
  return VERSION_META.exec(body)?.[1] ?? null;
}
