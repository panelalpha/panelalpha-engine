<?php

namespace App\Lib\Deploy\Telemetry;

/**
 * Assembles one bug report. Always about one deployed application, whose
 * evidence the engine gathers itself: what the last deploy decided, what the
 * project is (inspection), what it is doing now (a live health probe), and the
 * log tail at tier 2. Grouped by a fingerprint of the area and normalised title,
 * so fifty installs reporting one bug collapse into one issue.
 */
class BugReport
{
    public const SCHEMA = 1;

    /** What this report is, next to a deploy report's implicit `deploy`. */
    public const KIND = 'bug';

    /**
     * Kept because the spool refuses a file with no `outcome` and the ingest
     * stores one per row; borrowing `failed` would land in the deploy failure rate.
     */
    public const OUTCOME = 'reported';

    public const SEVERITY_LOW = 'low';
    public const SEVERITY_NORMAL = 'normal';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    /** @var list<string> */
    public const SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_NORMAL,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    public const DEFAULT_SEVERITY = self::SEVERITY_NORMAL;

    public const DEFAULT_AREA = 'other';

    /**
     * Suggested, not allowed: an open set, so a report about a part of the engine
     * this list has not heard of is still accepted. This list is what the CLI
     * offers and the API documents.
     *
     * @var list<string>
     */
    public const AREAS = [
        'deploy',
        'domains',
        'ssl',
        'dns',
        'mysql',
        'mail',
        'files',
        'backup',
        'containers',
        'metrics',
        'api',
        'mcp',
        'cli',
        'install',
        'panel',
        'other',
    ];

    /** Which surface filed it. Open set, same reasoning as the areas. */
    public const DEFAULT_VIA = 'api';

    /** Longest title kept, in bytes. */
    public const MAX_TITLE_BYTES = 200;

    /** Longest contact kept, in bytes. Fits any address a mail server accepts. */
    public const MAX_CONTACT_BYTES = 190;

    /**
     * Ceiling on the gathered evidence, in bytes of JSON. A backstop on top of the
     * per-list caps in Redactor::tree() — busting it drops the section, since half
     * of it would be misleading.
     */
    public const MAX_APP_BYTES = 65536;

    private const SLUG = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    /**
     * Keys whose value is a repository URL. An explicit list, not a pattern: a fuzzy
     * match would take framework homepages and registry addresses with it.
     *
     * @var list<string>
     */
    private const REPO_URL_KEYS = ['repository', 'repo', 'repo_url', 'git_repo', 'remote', 'origin'];

    /** A private repository whose URL could not be parsed at all. */
    private const PRIVATE_REPO = '<private>';

    /**
     * Dropped for a private project. The name is hashed, not dropped, since repeat
     * reports about one project still have to group.
     *
     * @var list<string>
     */
    private const OWN_PACKAGE_DETAILS = ['description', 'version', 'homepage', 'authors', 'keywords'];

    /**
     * Keys whose subtree describes somebody else's software: a framework's name and
     * version are public facts about a public package.
     *
     * @var list<string>
     */
    private const FOREIGN_PACKAGE_KEYS = ['framework', 'frameworks', 'platform', 'dependency_counts'];

    /**
     * @param array{
     *   id: string,
     *   occurred_at: int,
     *   tier: int,
     *   install_id: string,
     *   username: string,
     *   title: string,
     *   description: string,
     *   severity?: ?string,
     *   area?: ?string,
     *   contact?: ?string,
     *   via?: ?string,
     *   latest?: array<string, mixed>,
     *   details?: array<string, mixed>,
     *   repo_url?: ?string,
     *   repo_private?: bool,
     *   domains?: list<array<string, mixed>>,
     *   inspect?: ?array<string, mixed>,
     *   health?: ?array<string, mixed>,
     *   log_tail?: list<string>
     * } $input
     *
     * @return array<string, mixed>
     */
    public static function build(array $input): array
    {
        $tier = DeployReport::clampTier((int) $input['tier']);
        $username = (string) $input['username'];
        $installId = (string) $input['install_id'];
        $area = self::normalizeArea($input['area'] ?? null);

        $details = is_array($input['details'] ?? null) ? $input['details'] : [];
        $repoMask = PrivateRepoMask::for(
            (bool) ($input['repo_private'] ?? false),
            [is_string($input['repo_url'] ?? null) ? $input['repo_url'] : null],
            [is_string($details['git_branch'] ?? null) ? $details['git_branch'] : null]
        );

        $title = self::title((string) $input['title'], $username, $repoMask);
        $description = Redactor::prose(
            (string) $input['description'],
            $username ?: null,
            repo: $repoMask
        );

        $report = [
            'id' => (string) $input['id'],
            'schema' => self::SCHEMA,
            'kind' => self::KIND,
            'tier' => $tier,
            'occurred_at' => (int) $input['occurred_at'],
            'outcome' => self::OUTCOME,
            // The same salted hash a deploy report uses, so a bug report and that
            // account's failed deploys line up without either naming the customer.
            'account' => Fingerprint::account($installId, $username),
            'fingerprint' => self::fingerprint($area, $title),
            'bug' => self::bug($input, $area, $title, $description),
        ];

        $deploy = self::deploy($input['details'] ?? [], $input['latest'] ?? []);
        if ($deploy !== []) {
            $report['deploy'] = $deploy;
        }

        // The same block a deploy report carries, and it arrives even for a project
        // whose deploy predates `details.domain`.
        $domain = DeployReport::domainFacts(
            is_array($input['details'] ?? null) ? $input['details'] : [],
            $username,
            $input['domains'] ?? []
        );
        if ($domain !== []) {
            $report['domain'] = $domain;
        }

        $app = self::app($input, $username, $tier, $installId);
        if ($app !== []) {
            $report['app'] = $app;
        }

        // Build output is tier-2 material, as in a deploy report.
        if ($tier >= DeployReport::TIER_LOG) {
            $tail = Redactor::tail($input['log_tail'] ?? [], $username ?: null, $repoMask);
            if ($tail !== []) {
                $report['log_tail'] = $tail;
            }
        }

        return $report;
    }

    /**
     * The evidence: what the application is, and what it is doing. Gathered live
     * and scrubbed by shape, not by an allow-list of known-safe keys; the
     * whole section is dropped if it busts its budget. Either half may be absent —
     * a classic-template project has no container to probe, a rolled-back one
     * nothing to inspect — and absent says that.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function app(array $input, string $username, int $tier, string $installId): array
    {
        // An inspection *is* project identity, which tier 0 promises never leaves
        // the machine. Declared, not silently dropped, so the reader knows evidence
        // existed and was withheld.
        if ($tier < DeployReport::TIER_REPO) {
            return ['omitted' => 'tier'];
        }

        $private = (bool) ($input['repo_private'] ?? false);
        $app = [];

        foreach (['inspect', 'health'] as $section) {
            $value = $input[$section] ?? null;
            if (!is_array($value) || $value === []) {
                continue;
            }
            $app[$section] = self::guardPrivateIdentity(
                Redactor::tree($value, $username ?: null),
                $private,
                $installId
            );
        }

        if ($app === []) {
            return [];
        }

        if (strlen((string) json_encode($app)) > self::MAX_APP_BYTES) {
            // Say it was too big and let the reader ask.
            return ['omitted' => 'too-large'];
        }

        return $app;
    }

    /**
     * The deploy report's privacy rules applied to the gathered evidence, since an
     * inspection would otherwise quote in the clear what DeployReport and AppFacts
     * take care to hash: a private repository URL keeps its host and hashes its
     * path, a private package name is hashed and its descriptive fields dropped —
     * only where they describe the customer's own package. Salted by install id.
     *
     * @param mixed $value
     * @param bool $ownPackage whether this subtree describes the project itself
     * @return mixed
     */
    private static function guardPrivateIdentity(
        mixed $value,
        bool $private,
        string $installId,
        bool $ownPackage = false
    ): mixed {
        if (!$private || !is_array($value)) {
            return $value;
        }

        $guarded = [];
        foreach ($value as $key => $item) {
            $name = (string) $key;

            if (is_string($item) && in_array($name, self::REPO_URL_KEYS, true)) {
                $guarded[$key] = self::hashRepo($item, $installId);
                continue;
            }

            if ($ownPackage && $name === 'name' && is_string($item)) {
                $guarded[$key] = substr(hash('sha256', $installId . '|' . $item), 0, 16);
                continue;
            }

            if ($ownPackage && in_array($name, self::OWN_PACKAGE_DETAILS, true)) {
                $guarded[$key] = is_array($item) ? [] : null;
                continue;
            }

            $guarded[$key] = self::guardPrivateIdentity(
                $item,
                $private,
                $installId,
                self::describesOwnPackage($name, $item, $ownPackage)
            );
        }

        return $guarded;
    }

    /**
     * Does this subtree describe the project itself, not a dependency?
     * `metadata` opens the question and a `frameworks` entry closes it again. A
     * list inside an open subtree stays open, so `metadata.packages[]` inherits it.
     *
     * @param mixed $item
     */
    private static function describesOwnPackage(string $key, mixed $item, bool $ownPackage): bool
    {
        if (in_array($key, self::FOREIGN_PACKAGE_KEYS, true)) {
            return false;
        }

        return $ownPackage || ($key === 'metadata' && is_array($item));
    }

    /**
     * A private repository as its salted hash, in the shape DeployReport sends: the
     * host stays legible, the path becomes a per-install, per-repository hash.
     */
    private static function hashRepo(string $url, string $installId): string
    {
        $parts = DeployReport::parseRepoUrl($url);
        if ($parts === null) {
            return self::PRIVATE_REPO;
        }

        return $parts['host'] . '/' . substr(
            hash('sha256', $installId . '|' . $parts['host'] . '/' . $parts['path']),
            0,
            16
        );
    }

    /**
     * The report itself, as the reporter wrote it.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function bug(array $input, string $area, string $title, string $description): array
    {
        $bug = [
            'severity' => self::normalizeSeverity($input['severity'] ?? null),
            'area' => $area,
            'title' => $title,
            'description' => $description,
            'via' => self::normalizeVia($input['via'] ?? null),
        ];

        // Absent unless somebody typed one: present-and-null reads as "we looked
        // and found none".
        $contact = self::contact($input['contact'] ?? null);
        if ($contact !== null) {
            $bug['contact'] = $contact;
        }

        return $bug;
    }

    /**
     * What the named project's last deploy was, when there is one: the same fields
     * a deploy report carries. Empty, not a husk of nulls, when the account has
     * never deployed.
     *
     * @param array<string, mixed> $details
     * @param array<string, mixed> $latest
     * @return array<string, mixed>
     */
    private static function deploy(array $details, array $latest): array
    {
        return array_filter([
            'id' => self::stringOrNull($latest['id'] ?? null),
            'status' => self::stringOrNull($latest['status'] ?? null),
            'stage' => self::stringOrNull($latest['stage'] ?? null),
            'source' => self::stringOrNull($details['deploy_source'] ?? null),
            'strategy' => self::stringOrNull($details['deploy_strategy'] ?? null),
            'label' => self::stringOrNull($details['deploy_label'] ?? null),
            'runtime' => self::stringOrNull($details['deploy_runtime'] ?? null),
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * The grouping key: area plus the normalised title, not the description —
     * two people describe one bug in two paragraphs and the same headline.
     * Normalisation keeps numbers, paths and ids from splitting a group.
     */
    public static function fingerprint(string $area, string $title): string
    {
        return substr(
            hash('sha256', self::KIND . '|' . $area . '|' . Fingerprint::normalize($title)),
            0,
            Fingerprint::LENGTH
        );
    }

    public static function normalizeSeverity(?string $severity): string
    {
        $severity = strtolower(trim((string) $severity));

        return in_array($severity, self::SEVERITIES, true) ? $severity : self::DEFAULT_SEVERITY;
    }

    /** A slug, or `other`. Never rejected: an unknown area is still a report. */
    public static function normalizeArea(?string $area): string
    {
        return self::slug($area) ?? self::DEFAULT_AREA;
    }

    public static function normalizeVia(?string $via): string
    {
        return self::slug($via) ?? self::DEFAULT_VIA;
    }

    private static function slug(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        $value = (string) preg_replace('/[\s_]+/', '-', $value);

        return preg_match(self::SLUG, $value) === 1 ? $value : null;
    }

    /**
     * The headline, redacted and capped to one line: someone will eventually put a
     * token in a title, and newlines break every list view.
     */
    private static function title(string $title, string $username, ?PrivateRepoMask $repoMask): string
    {
        $title = (string) preg_replace('/\s+/', ' ', $title);

        return Redactor::line(trim($title), $username ?: null, self::MAX_TITLE_BYTES, $repoMask);
    }

    /**
     * The reporter's own address, exactly as typed and never redacted: a bug report
     * is a conversation, and support has to be able to answer it.
     */
    private static function contact(mixed $contact): ?string
    {
        if (!is_string($contact)) {
            return null;
        }

        $contact = trim((string) preg_replace('/\s+/', ' ', $contact));
        if ($contact === '') {
            return null;
        }

        return strlen($contact) > self::MAX_CONTACT_BYTES
            ? mb_strcut($contact, 0, self::MAX_CONTACT_BYTES)
            : $contact;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
