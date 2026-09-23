/**
 * A slice of the Supported column on the supported-apps board
 * (https://git.modulesgarden.tech/panelalpha/playground/supported-apps/-/boards).
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
}

/** Title reduced to lowercase letters and digits. */
export function supportedAppSlug(title: string): string {
  const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '');
  return slug.length > 0 ? slug : 'app';
}

function app(iid: number, title: string, repo: string, stack: SupportedAppStack): SupportedApp {
  return {
    iid,
    title,
    repo,
    stack,
    issueUrl: `https://git.modulesgarden.tech/panelalpha/playground/supported-apps/-/work_items/${iid}`,
    slug: supportedAppSlug(title),
  };
}

export const SUPPORTED_APPS: readonly SupportedApp[] = [
  // PHP
  app(42, 'FreshRSS', 'https://github.com/FreshRSS/FreshRSS', 'php'),
  app(61, 'Roundcube', 'https://github.com/roundcube/roundcubemail', 'php'),
  app(312, 'osTicket', 'https://github.com/osTicket/osTicket', 'php'),
  app(332, 'Easy!Appointments', 'https://github.com/alextselegidis/easyappointments', 'php'),
  app(335, 'OpenCart', 'https://github.com/opencart/opencart', 'php'),
  app(309, 'SuiteCRM', 'https://github.com/SuiteCRM/SuiteCRM', 'php'),
  app(334, 'PrestaShop', 'https://github.com/PrestaShop/PrestaShop', 'php'),
  app(18, 'Ampache', 'https://github.com/ampache/ampache', 'php'),
  app(941, 'Servas', 'https://github.com/beromir/Servas', 'php'),
  app(340, 'Heimdall', 'https://github.com/linuxserver/Heimdall', 'php'),
  app(1522, 'Dotclear (GitHub mirror)', 'https://github.com/dotclear/dotclear', 'php'),
  app(1521, 'Kirby Starterkit', 'https://github.com/getkirby/starterkit', 'php'),
  app(1524, 'PmWiki (git mirror)', 'https://github.com/l2dy-sonarcloud/pmwiki', 'php'),
  app(1348, 'b1gMail', 'https://codeberg.org/b1gMail/b1gMail', 'php'),

  // Laravel
  app(8, 'Firefly III', 'https://github.com/firefly-iii/firefly-iii', 'laravel'),
  app(19, 'Koel', 'https://github.com/koel/koel', 'laravel'),
  app(339, 'Cachet', 'https://github.com/cachethq/cachet', 'laravel'),
  app(1520, 'MixpostApp', 'https://github.com/inovector/MixpostApp', 'laravel'),

  // Python
  app(191, 'MeTube', 'https://github.com/alexta69/metube', 'python'),
  app(177, 'Ownfoil', 'https://github.com/a1ex4/ownfoil', 'python'),
  app(1201, 'juntagrico', 'https://github.com/juntagrico/juntagrico', 'python'),
  app(
    1523,
    'Roundup Issue Tracker (GitHub mirror)',
    'https://github.com/roundup-tracker/roundup',
    'python'
  ),
  app(1525, 'Trac (GitHub mirror)', 'https://github.com/edgewall/trac', 'python'),

  // Node.js
  app(26, 'Umami', 'https://github.com/umami-software/umami', 'nodejs'),
  app(31, 'Outline', 'https://github.com/outline/outline', 'nodejs'),
  app(331, 'Cal.com', 'https://github.com/calcom/cal.diy', 'nodejs'),
  app(194, 'Seerr', 'https://github.com/seerr-team/seerr', 'nodejs'),
  app(907, 'Kiwi IRC', 'https://github.com/kiwiirc/kiwiirc', 'nodejs'),
  app(799, 'ChronoFrame', 'https://github.com/HoshinoSuzumi/chronoframe', 'nodejs'),
  app(1111, 'GitProxy', 'https://github.com/finos/git-proxy', 'nodejs'),
  app(1214, 'Hive-Pal', 'https://github.com/martinhrvn/hive-pal', 'nodejs'),
  app(157, 'Pingvin Share X', 'https://github.com/smp46/pingvin-share-x', 'nodejs'),

  // Go
  app(338, 'ntfy', 'https://github.com/binwiederhier/ntfy', 'go'),
  app(101, 'Memos', 'https://github.com/usememos/memos', 'go'),
  app(374, 'Answer', 'https://github.com/apache/answer', 'go'),
  app(1334, 'Tube', 'https://git.mills.io/prologic/tube', 'go'),
  app(1376, 'Betula', 'https://codeberg.org/bouncepaw/betula', 'go'),
  app(512, 'Cloudreve', 'https://github.com/cloudreve/cloudreve', 'go'),
  app(257, 'Casdoor', 'https://github.com/casdoor/casdoor', 'go'),

  // Java, Rust, Ruby
  app(924, 'Scoold', 'https://github.com/Erudika/scoold', 'java'),
  app(1225, 'BinPastes', 'https://github.com/querwurzel/BinPastes', 'java'),
  app(730, 'Stump', 'https://github.com/stumpapp/stump', 'rust'),
  app(1068, 'Foodsoft', 'https://github.com/foodcoops/foodsoft', 'ruby'),
  app(310, 'Chatwoot', 'https://github.com/chatwoot/chatwoot', 'ruby'),
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
