<?php

namespace App\Console\Wizard\Sections;

use App\Console\Prompts\Screen;
use App\Console\Wizard\KeepsAReceipt;
use App\Console\Wizard\Section;
use App\Lib\Ssl\EngineCertificateRequest;
use App\Lib\Ssl\ServedCertificate;
use App\Models\Setting;
use App\Support\EnvFile;
use App\System;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * The address this engine answers on, and the certificate it answers with.
 *
 * Three settings that are one errand, and are otherwise three unconnected
 * places:
 *
 *  - `APP_URL` is the address handed to every assistant and API client. An
 *    engine that has not been told its own address prints setup commands that
 *    cannot work, and they fail on somebody else's machine.
 *  - `cert_domain` is the name the engine's certificate is issued for — and
 *    not only that: new project sites are named under it, so changing it moves
 *    where sites live.
 *  - The certificate itself, which has to cover the name in `APP_URL` or
 *    every client refuses the connection it was just told to make.
 *
 * Whatever is configured, the header reports what is **actually served** on
 * the address, read off the wire. Those drift — a renewal certbot wrote but
 * the webserver has not picked up looks fine in every file and wrong to every
 * client — and the served one is what the question is about.
 */
class EngineAddressSection implements Section
{
    use KeepsAReceipt;

    /** Whether the settings table could be read at all this run. */
    private bool $database = true;

    public static function key(): string
    {
        return 'address';
    }

    public static function label(): string
    {
        return 'Address & certificate — how this engine is reached';
    }

    public static function hint(): string
    {
        return 'The address clients connect to, and the certificate it answers with.';
    }

    /**
     * Say so when the settings file this writes to is not the one the engine
     * reads. Everything below would otherwise report success and do nothing.
     */
    private function warnAboutEnv(): void
    {
        $why = EnvFile::current()->suspicious();

        if ($why !== null) {
            warning($why);
        }
    }

    public function run(bool $dryRun): int
    {
        while (true) {
            $url = $this->url();
            $served = $this->header($url);

            switch ((string) select(
                label: 'What would you like to change?',
                options: [
                    'url' => 'Address        — what clients are told to connect to',
                    'name' => 'Certificate name — what the certificate is issued for',
                    'request' => 'Request or renew the certificate',
                    'check' => 'Check it again',
                    'back' => 'Back',
                ],
                default: $this->suggestion($url, $served),
                scroll: 5,
                hint: 'The certificate has to cover the name in the address, or clients refuse it.',
            )) {
                case 'url':
                    $this->setUrl($url, $dryRun);
                    break;

                case 'name':
                    if ($this->database) {
                        $this->setName($dryRun);
                    }

                    break;

                case 'request':
                    if ($this->database) {
                        $this->request($dryRun);
                    }

                    break;

                case 'check':
                    break;

                default:
                    return 0;
            }
        }
    }

    /**
     * What is configured and what is actually answering, side by side.
     *
     * @return array<string, mixed>|null the served certificate
     */
    private function header(string $url): ?array
    {
        $certDomain = (string) ($this->setting('cert_domain') ?? '');
        $served = $url === '' ? null : ServedCertificate::at($url);

        // The configured address is the one worth checking, but an engine that
        // does not know its own address still has a certificate, and saying
        // nothing about it would be unhelpful precisely when help is needed.
        $local = $served === null ? ServedCertificate::at('127.0.0.1:2011') : null;
        $certificate = $served ?? $local;

        $lines = [
            sprintf('Address:     %s', $url === '' ? '(not set)' : $url),
            sprintf('Certificate name: %s', match (true) {
                !$this->database => '(unknown — the database could not be read)',
                $certDomain === '' => '(default — the .panelalpha.direct name for this address)',
                default => $certDomain,
            }),
        ];

        if ($certificate === null) {
            $lines[] = 'Serving:     nothing answered on this address or on :2011.';
        } else {
            $lines[] = sprintf(
                'Serving:     %s, %s, expires %s%s',
                $certificate['name'] === '' ? '(no name)' : $certificate['name'],
                $certificate['self_signed'] ? 'self-signed' : 'issued by ' . $certificate['issuer'],
                date('Y-m-d', $certificate['expires']),
                $served === null ? '  (read on :2011 — the address above did not answer)' : ''
            );
        }

        Screen::draw(self::label(), implode("\n", $lines));

        $this->warnAboutEnv();

        if (!$this->database) {
            // The address half is env and still works; the rest needs the
            // settings table, and saying which is which beats one failure.
            warning('The database could not be read, so the certificate name cannot be shown or changed. The address below still can.');
        }

        $this->warnings($url, $certificate, $served !== null);

        return $certificate;
    }

    /** @param array<string, mixed>|null $certificate */
    private function warnings(string $url, ?array $certificate, bool $reachable): void
    {
        $host = $url === '' ? '' : (string) parse_url($url, PHP_URL_HOST);

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            warning(
                'No client can reach this engine at that address. Set it to the name or public '
                . 'address you connect on, including the port.'
            );

            return;
        }

        if (str_starts_with($url, 'http://')) {
            warning('The address is plain http. Most assistants refuse an MCP server that is not https.');
        }

        if (!$reachable) {
            warning('Nothing answered on that address from here. It may still work from outside, but nothing confirms it.');
        }

        if ($certificate === null) {
            return;
        }

        if ($certificate['self_signed']) {
            warning('The certificate is self-signed, so clients will refuse it unless they were told to trust it. Requesting one below fixes that.');
        }

        if ($reachable && !ServedCertificate::covers($certificate, $host)) {
            warning(sprintf(
                'The certificate does not cover "%s". It is for %s.',
                $host,
                implode(', ', $certificate['names']) ?: '(no name)'
            ));
        }

        $days = (int) floor(($certificate['expires'] - time()) / 86400);

        if ($days <= 21) {
            warning(sprintf(
                $days < 0 ? 'The certificate expired %d days ago.' : 'The certificate expires in %d days.',
                abs($days)
            ));
        }
    }

    /** Which row to put the cursor on: the first thing that looks wrong. */
    private function suggestion(string $url, ?array $served): string
    {
        $host = $url === '' ? '' : (string) parse_url($url, PHP_URL_HOST);

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return 'url';
        }

        return $served === null || $served['self_signed'] ? 'request' : 'check';
    }

    private function setUrl(string $current, bool $dryRun): void
    {
        Screen::draw(self::label() . '  ·  Address');
        note(
            "This is what an assistant or an API client is told to connect to, port included.\n"
            . 'It has to be a name or address reachable from wherever they run, not from here.'
        );

        $url = trim(text(
            label: 'The address this engine answers on',
            default: $current,
            placeholder: 'https://panel.example.com:2011',
            hint: 'Include https:// and the port.',
            validate: fn (string $v): ?string => $this->badUrl(trim($v)),
        ));

        if ($url === '' || $url === $current) {
            return;
        }

        if ($dryRun) {
            note(sprintf('Dry run: APP_URL would become %s.', $url), 'warning');
            pause('Press enter to carry on...');

            return;
        }

        $this->write(['APP_URL' => $url], sprintf('Address set to %s.', $url));

        // The process booted with the old one and the header reads config();
        // without this the screen would still show what was just replaced.
        config(['app.url' => $url]);

        note(
            "The engine uses this on its next request, so `pae connect` prints the new address now.\n"
            . 'Clients already connected keep the old one until they are set up again.',
            'warning'
        );
        pause('Press enter to carry on...');
    }

    private function setName(bool $dryRun): void
    {
        Screen::draw(self::label() . '  ·  Certificate name');
        note(
            "The name the engine's certificate is issued for.\n\n"
            . "It is also the name new sites are given under, so changing it moves where\n"
            . 'sites created from now on will live. Leave it empty to use the default '
            . 'built from this engine\'s public address.'
        );

        $current = (string) ($this->setting('cert_domain') ?? '');

        $name = strtolower(trim(text(
            label: $current === ''
                ? 'Certificate name — empty means the default for this address'
                : 'Certificate name',
            default: $current,
            // No example here. An empty field showing `panel.example.com` in
            // grey reads as the value, and this is the one setting where
            // believing a name is set when it is not sends new sites
            // somewhere nobody expects.
            hint: 'A name that already points at this server. Empty uses the .panelalpha.direct default.',
            // Validated because this one is quietly load-bearing: it names
            // every project site created from now on, so a typo does not fail
            // loudly — it sends new sites somewhere nobody expects, and the
            // certificate request then fails for a name that was never real.
            validate: fn (string $v): ?string => $this->badName(trim($v)),
        )));

        $email = strtolower(trim(text(
            label: "Let's Encrypt account email",
            default: (string) ($this->setting('cert_email') ?? ''),
            hint: "Where Let's Encrypt sends expiry warnings. Optional — empty registers without one.",
            validate: fn (string $v): ?string => trim($v) === '' || filter_var(trim($v), FILTER_VALIDATE_EMAIL)
                ? null
                : 'That is not an email address.',
        )));

        if ($dryRun) {
            note('Dry run: nothing was written.', 'warning');
            pause('Press enter to carry on...');

            return;
        }

        try {
            Setting::set('cert_domain', $name);
            Setting::set('cert_email', $email);
        } catch (Throwable $e) {
            error($e->getMessage());
            pause('Press enter to carry on...');

            return;
        }

        $this->receipt[] = $name === ''
            ? 'Certificate name cleared — the default for this address will be used.'
            : sprintf('Certificate name set to %s.', $name);

        note(end($this->receipt), 'info');
        note('Nothing is issued yet. "Request or renew the certificate" does that.');
        pause('Press enter to carry on...');
    }

    private function request(bool $dryRun): void
    {
        $name = (string) ($this->setting('cert_domain') ?? '');

        Screen::draw(self::label() . '  ·  Certificate');
        note(sprintf(
            "About to ask Let's Encrypt for a certificate for %s.\n\n"
            . "The name has to resolve to this server already, and the engine restarts its\n"
            . 'webserver around the request, so :2011 is briefly unavailable.',
            $name === '' ? 'the default name for this address' : $name
        ));

        $mode = (string) select(
            label: 'How would you like to run it?',
            options: [
                'real' => 'Request it for real',
                'dry' => 'Rehearse it — full check, no certificate issued',
                'staging' => "Use Let's Encrypt staging — issues an untrusted certificate, no rate limits",
                'back' => 'Back',
            ],
            default: 'dry',
            scroll: 4,
            hint: 'Rehearse first if you are not sure the name points here yet.',
        );

        if ($mode === 'back') {
            return;
        }

        if ($dryRun) {
            note('Dry run: no request was made.', 'warning');
            pause('Press enter to carry on...');

            return;
        }

        if ($mode === 'real' && !confirm(
            label: 'Request it now? :2011 goes down for a moment.',
            default: true,
        )) {
            return;
        }

        $flags = match ($mode) {
            'dry' => ['dry-run'],
            'staging' => ['staging'],
            default => [],
        };

        $result = spin(
            fn (): array => EngineCertificateRequest::run(new System(), [], $flags),
            'Talking to Let\'s Encrypt — this takes a minute...'
        );

        Screen::draw(self::label() . '  ·  Certificate');
        note($result['output'] === '' ? '(no output)' : $result['output']);

        if (!$result['successful']) {
            warning('The request did not succeed. The output above says why; the usual cause is the name not pointing at this server yet.');
            pause('Press enter to carry on...');

            return;
        }

        if ($mode === 'real') {
            $this->receipt[] = sprintf(
                'Issued a certificate for %s.',
                $result['cert_domain'] ?? 'this engine'
            );
            note(end($this->receipt), 'info');
        } else {
            note('That was a rehearsal. Nothing was installed.', 'warning');
        }

        pause('Press enter to carry on...');
    }

    /**
     * A hostname, or nothing. Deliberately strict about the shape rather than
     * clever about the name: two labels at least, letters, digits and hyphens,
     * no leading or trailing dot.
     */
    private function badName(string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        if (!str_contains($name, '.')) {
            return 'A certificate name is a full hostname, like panel.example.com.';
        }

        return preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i', $name) === 1
            ? null
            : 'That is not a hostname. Letters, digits and hyphens, in labels separated by dots.';
    }

    private function badUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '' || !str_contains($url, '://')) {
            return 'Give the whole address, including https:// and the port.';
        }

        return null;
    }

    /**
     * @param array<string, string> $values
     */
    private function write(array $values, string $said): void
    {
        try {
            EnvFile::current()->set($values);
        } catch (Throwable $e) {
            error($e->getMessage());
            pause('Press enter to carry on...');

            return;
        }

        $this->receipt[] = $said;
        note($said, 'info');
    }

    private function url(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * A stored setting, or null when the database is not there.
     *
     * `cert_domain` lives in the settings table while `APP_URL` is env, so a
     * database that is down takes half this section with it. Half is worth
     * keeping: the address is the thing most likely to be wrong, and it is
     * the half that does not need a database.
     */
    private function setting(string $key): ?string
    {
        if (!$this->database) {
            return null;
        }

        try {
            $value = Setting::get($key);
        } catch (Throwable) {
            $this->database = false;

            return null;
        }

        return is_string($value) ? $value : null;
    }
}
