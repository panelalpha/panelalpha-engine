/**
 * A slice of the applications the tracker marks Supported.
 * Set SUPPORTED_APPS_TRACKER_URL to link entries to the tracker.
 *
 * Not the whole board — about forty applications, a few from each stack the
 * engine actually deploys. `slug` is the title reduced to letters and digits,
 * not truncated.
 */

export type SupportedAppStack =
  'php' | 'laravel' | 'python' | 'nodejs' | 'go' | 'java' | 'rust' | 'ruby';

export interface SupportedApp {
  iid: number;
  title: string;
  repo: string;
  stack: SupportedAppStack;
  issueUrl: string;
  slug: string;
  /** What the front page, redirects followed, holds when it is this application. */
  page?: RegExp;
  /** The branch to deploy when the default branch is not the application. */
  branch?: string;
}

/** Title reduced to lowercase letters and digits. */
export function supportedAppSlug(title: string): string {
  const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '');
  return slug.length > 0 ? slug : 'app';
}

const TRACKER_URL =
  process.env.SUPPORTED_APPS_TRACKER_URL ?? 'https://tracker.example.com/supported-apps';

function app(
  iid: number,
  title: string,
  repo: string,
  stack: SupportedAppStack,
  page?: RegExp,
  branch?: string
): SupportedApp {
  return {
    iid,
    title,
    repo,
    stack,
    issueUrl: `${TRACKER_URL}/-/work_items/${iid}`,
    slug: supportedAppSlug(title),
    ...(page === undefined ? {} : { page }),
    ...(branch === undefined ? {} : { branch }),
  };
}

export const SUPPORTED_APPS: readonly SupportedApp[] = [
  // PHP
  app(42, 'FreshRSS', 'https://github.com/FreshRSS/FreshRSS', 'php', /FreshRSS/),
  app(
    61,
    'Roundcube',
    'https://github.com/roundcube/roundcubemail',
    'php',
    /<title[^>]*>\s*[^<]*Roundcube Webmail/
  ),
  app(312, 'osTicket', 'https://github.com/osTicket/osTicket', 'php', /<title[^>]*>\s*osTicket/),
  app(
    335,
    'OpenCart',
    'https://github.com/opencart/opencart',
    'php',
    /<title[^>]*>\s*License agreement/,
    '4.1.x.x'
  ),
  app(309, 'SuiteCRM', 'https://github.com/SuiteCRM/SuiteCRM', 'php', /<title[^>]*>\s*SuiteCRM/),
  app(
    334,
    'PrestaShop',
    'https://github.com/PrestaShop/PrestaShop',
    'php',
    /<title[^>]*>\s*PrestaShop/
  ),
  app(18, 'Ampache', 'https://github.com/ampache/ampache', 'php', /<title[^>]*>\s*Ampache/),
  app(
    941,
    'Servas',
    'https://github.com/beromir/Servas',
    'php',
    /data-page="\{&quot;component&quot;:/
  ),
  app(
    340,
    'Heimdall',
    'https://github.com/linuxserver/Heimdall',
    'php',
    /<title[^>]*>\s*Heimdall<\/title>/
  ),
  app(
    1522,
    'Dotclear (GitHub mirror)',
    'https://github.com/dotclear/dotclear',
    'php',
    /<title[^>]*>\s*My first blog/
  ),
  app(
    1521,
    'Kirby Starterkit',
    'https://github.com/getkirby/starterkit',
    'php',
    /<title[^>]*>\s*Mægazine/
  ),
  app(
    1524,
    'PmWiki (git mirror)',
    'https://github.com/l2dy-sonarcloud/pmwiki',
    'php',
    /<title[^>]*>\s*PmWiki/
  ),
  app(
    1348,
    'b1gMail',
    'https://codeberg.org/b1gMail/b1gMail',
    'php',
    /<title[^>]*>\s*[^<]+ Mail<\/title>[\s\S]*templates\/modern\//
  ),

  // Laravel
  app(8, 'Firefly III', 'https://github.com/firefly-iii/firefly-iii', 'laravel', /Firefly III/),
  app(19, 'Koel', 'https://github.com/koel/koel', 'laravel', /<title[^>]*>\s*Koel/),
  app(339, 'Cachet', 'https://github.com/cachethq/cachet', 'laravel', /<title[^>]*>\s*Cachet/),
  app(
    1520,
    'MixpostApp',
    'https://github.com/inovector/MixpostApp',
    'laravel',
    /<title[^>]*>\s*[^<]*Mixpost/
  ),

  // Python
  app(191, 'MeTube', 'https://github.com/alexta69/metube', 'python', /<title[^>]*>\s*MeTube/),
  app(177, 'Ownfoil', 'https://github.com/a1ex4/ownfoil', 'python', /<title[^>]*>\s*Ownfoil/),
  app(
    1523,
    'Roundup Issue Tracker (GitHub mirror)',
    'https://github.com/roundup-tracker/roundup',
    'python',
    /<title[^>]*>\s*List of issues/
  ),
  app(
    1525,
    'Trac (GitHub mirror)',
    'https://github.com/edgewall/trac',
    'python',
    /<title[^>]*>\s*Trac\b/
  ),

  // Node.js
  app(26, 'Umami', 'https://github.com/umami-software/umami', 'nodejs', /<title[^>]*>\s*Umami/),
  app(31, 'Outline', 'https://github.com/outline/outline', 'nodejs', /<title[^>]*>\s*Outline/),
  app(
    331,
    'Cal.com',
    'https://github.com/calcom/cal.diy',
    'nodejs',
    /<title[^>]*>\s*[^<]*Cal\.(diy|com)/
  ),
  app(194, 'Seerr', 'https://github.com/seerr-team/seerr', 'nodejs', /<title[^>]*>\s*[^<]*Seerr/),
  app(907, 'Kiwi IRC', 'https://github.com/kiwiirc/kiwiirc', 'nodejs', /<title[^>]*>\s*Kiwi IRC/),
  app(799, 'ChronoFrame', 'https://github.com/HoshinoSuzumi/chronoframe', 'nodejs'),
  app(1111, 'GitProxy', 'https://github.com/finos/git-proxy', 'nodejs', /<title[^>]*>\s*GitProxy/),
  app(
    1214,
    'Hive-Pal',
    'https://github.com/martinhrvn/hive-pal',
    'nodejs',
    /<title[^>]*>\s*Hive Pal/
  ),
  app(
    157,
    'Pingvin Share X',
    'https://github.com/smp46/pingvin-share-x',
    'nodejs',
    /<title[^>]*>\s*[^<]*Pingvin Share X/
  ),

  // Go
  app(338, 'ntfy', 'https://github.com/binwiederhier/ntfy', 'go', /<title[^>]*>\s*ntfy/),
  app(101, 'Memos', 'https://github.com/usememos/memos', 'go', /<title[^>]*>\s*Memos/),
  app(
    374,
    'Answer',
    'https://github.com/apache/answer',
    'go',
    /<meta name="generator" content="Answer - https:\/\/github\.com\/apache\/answer">/
  ),
  app(1334, 'Tube', 'https://git.mills.io/prologic/tube', 'go', /<title[^>]*>\s*Tube/),
  app(1376, 'Betula', 'https://codeberg.org/bouncepaw/betula', 'go', /<title[^>]*>\s*[^<]*Betula/),
  app(512, 'Cloudreve', 'https://github.com/cloudreve/cloudreve', 'go', /<title[^>]*>\s*Cloudreve/),
  app(257, 'Casdoor', 'https://github.com/casdoor/casdoor', 'go', /<title[^>]*>\s*Casdoor/),

  // Java, Rust, Ruby
  app(924, 'Scoold', 'https://github.com/Erudika/scoold', 'java', /<title[^>]*>\s*Scoold/),
  app(
    1225,
    'BinPastes',
    'https://github.com/querwurzel/BinPastes',
    'java',
    /<title[^>]*>\s*BinPastes/
  ),
  app(730, 'Stump', 'https://github.com/stumpapp/stump', 'rust', /<title[^>]*>\s*Stump/),
  app(1068, 'Foodsoft', 'https://github.com/foodcoops/foodsoft', 'ruby', /<title[^>]*>\s*Foodsoft/),
  app(
    310,
    'Chatwoot',
    'https://github.com/chatwoot/chatwoot',
    'ruby',
    /<title[^>]*>\s*[^<]*Chatwoot/
  ),
];

/**
 * `SUPPORTED_APPS=koel,ntfy` runs only those slugs. Unset runs the whole catalogue.
 */
export function selectedSupportedApps(filter: string | undefined): readonly SupportedApp[] {
  const wanted = (filter ?? '')
    .split(',')
    .map((slug) => slug.trim().toLowerCase())
    .filter((slug) => slug.length > 0);
  if (wanted.length === 0) {
    return SUPPORTED_APPS;
  }
  const known = new Set(SUPPORTED_APPS.map((entry) => entry.slug));
  const unknown = wanted.filter((slug) => !known.has(slug));
  if (unknown.length > 0) {
    throw new Error(
      `SUPPORTED_APPS has unknown slug(s): ${unknown.join(', ')}. Known: ${[...known].sort().join(', ')}`
    );
  }
  const picked = new Set(wanted);
  return SUPPORTED_APPS.filter((entry) => picked.has(entry.slug));
}
