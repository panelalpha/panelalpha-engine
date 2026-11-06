import { lookup } from 'node:dns/promises';
import https from 'node:https';

export interface Probe {
  /** Milliseconds since the watch started. */
  at: number;
  status: number | null;
  /** What `versionOf` read from the page, null when it found none. */
  version: string | null;
  error: string | null;
}

export interface WatchReport {
  probes: Probe[];
  /** Probes that were not the app: no answer, a status of 400 or more, or no version on the page. */
  failures: Probe[];
  /** The longest time between two probes that both served a version. */
  longestGapMs: number;
  versions: string[];
}

/** Polls a site on a fresh connection to an address resolved once, recording what visitors saw. */
export class SiteWatch {
  private readonly probes: Probe[] = [];
  private running = true;
  private readonly started = Date.now();
  private readonly loop: Promise<void>;

  constructor(
    private readonly url: string,
    private readonly versionOf: (body: string) => string | null,
    private readonly intervalMs = 250
  ) {
    this.loop = this.run();
  }

  async stop(): Promise<WatchReport> {
    this.running = false;
    await this.loop;
    return summarise(this.probes);
  }

  private async run(): Promise<void> {
    const address = (await lookup(new URL(this.url).hostname)).address;
    while (this.running) {
      const begun = Date.now();
      this.probes.push(await this.probe(address));
      const wait = this.intervalMs - (Date.now() - begun);
      if (wait > 0) {
        await new Promise((resolve) => setTimeout(resolve, wait));
      }
    }
  }

  private async probe(address: string): Promise<Probe> {
    const at = Date.now() - this.started;
    try {
      const { status, body } = await getPinned(this.url, address, 5_000);
      return { at, status, version: this.versionOf(body), error: null };
    } catch (error) {
      return {
        at,
        status: null,
        version: null,
        error: (error instanceof Error ? error.message : String(error)).split('\n')[0],
      };
    }
  }
}

function getPinned(
  url: string,
  address: string,
  timeoutMs: number
): Promise<{ status: number; body: string }> {
  const target = new URL(url);
  return new Promise((resolve, reject) => {
    const request = https.get(
      {
        host: address,
        port: target.port === '' ? 443 : Number(target.port),
        path: `${target.pathname}${target.search}`,
        servername: target.hostname,
        rejectUnauthorized: false,
        agent: false,
        headers: {
          Host: target.host,
          Accept: 'text/html,*/*;q=0.8',
          'Cache-Control': 'no-cache',
          Connection: 'close',
        },
        timeout: timeoutMs,
      },
      (response) => {
        let body = '';
        response.setEncoding('utf8');
        response.on('data', (chunk: string) => (body += chunk));
        response.on('end', () => resolve({ status: response.statusCode ?? 0, body }));
        response.on('error', reject);
      }
    );
    request.on('timeout', () => request.destroy(new Error(`no answer within ${timeoutMs} ms`)));
    request.on('error', reject);
  });
}

function summarise(probes: Probe[]): WatchReport {
  const failures = probes.filter(
    (probe) => probe.status === null || probe.status >= 400 || probe.version === null
  );
  let longestGapMs = 0;
  let lastGood: number | null = null;
  for (const probe of probes) {
    if (probe.status !== null && probe.status < 400 && probe.version !== null) {
      if (lastGood !== null) {
        longestGapMs = Math.max(longestGapMs, probe.at - lastGood);
      }
      lastGood = probe.at;
    }
  }
  const versions: string[] = [];
  for (const probe of probes) {
    if (probe.version !== null && versions.at(-1) !== probe.version) {
      versions.push(probe.version);
    }
  }
  return { probes, failures, longestGapMs, versions };
}

/** A short text for an assertion message and the report annotation. */
export function describeWatch(report: WatchReport): string {
  const sample = report.failures
    .slice(0, 5)
    .map((probe) => `+${probe.at}ms ${probe.status ?? probe.error} version=${probe.version}`)
    .join('; ');
  return (
    `${report.probes.length} probes, ${report.failures.length} failed, longest gap ` +
    `${report.longestGapMs}ms, versions served ${report.versions.join(' -> ')}` +
    (sample === '' ? '' : `; first failures: ${sample}`)
  );
}
