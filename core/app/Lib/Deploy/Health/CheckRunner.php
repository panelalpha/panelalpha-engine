<?php

namespace App\Lib\Deploy\Health;

use App\Lib\Deploy\Platform\PlatformMatcher;
use App\Lib\Deploy\Platform\Probes\ProbeRegistry;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Asks an application the checks its runtime brings, and says what the wrong answers mean:
 * the content question a port probe cannot answer. Nothing here fails a deploy.
 */
final class CheckRunner
{
    /** What an application serving itself correctly is reported as. */
    public const SERVING_OK = 'ok';

    /** Nothing answered, so no check had anything to look at. */
    public const SERVING_UNKNOWN = 'unknown';

    /** The engine's own "Project not configured" page answered: no application is running. */
    public const SERVING_PLACEHOLDER = 'placeholder';

    /** Nothing answered and Docker says why: the container keeps exiting. Not read from a response. */
    public const SERVING_RESTARTING = 'restarting';

    /** Nothing answered because the app container is stopped, and not restarting. */
    public const SERVING_STOPPED = 'stopped';

    /** @var array<string, array{check: string, reason: string, covered_by: ?string}> by reference */
    private readonly array $skips;

    /**
     * @param  list<HealthCheck>  $checks
     * @param  list<array{check: string, reason: string, covered_by: ?string}>  $skips  the manifest's `check_skip` entries
     */
    public function __construct(private readonly array $checks, array $skips = [])
    {
        $byReference = [];
        foreach ($skips as $skip) {
            $byReference[$skip['check']] = $skip;
        }
        $this->skips = $byReference;
    }

    /**
     * The checks for an application with this runtime, plus whatever its manifest added.
     *
     * @param  list<string>  $references
     */
    public static function for(?string $runtime, array $references = []): self
    {
        return new self(CheckRegistry::for($runtime, $references));
    }

    /**
     * The same, plus the checks a source recipe directory ships of its own.
     *
     * @param  list<string>  $references
     * @param  list<array{check: string, reason: string, covered_by: ?string}>  $skips
     */
    public static function forWithDirectory(
        ?string $runtime,
        array $references,
        ?string $directory,
        array $skips = []
    ): self {
        return new self(CheckRegistry::for($runtime, $references, $directory), $skips);
    }

    /**
     * The paths besides `/` these checks ask about, each once.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];
        foreach ($this->checks as $check) {
            $path = $check->path();
            if ($path !== HealthCheck::DEFAULT_PATH && ! in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Ask every check about the `/` response.
     *
     * @return array{serving: string, checks: list<array<string, mixed>>}
     */
    public function run(ProbedResponse $response, ?string $projectDir): array
    {
        return $this->runByPath([HealthCheck::DEFAULT_PATH => $response], $projectDir);
    }

    /**
     * Ask each check about the response for the path it named; a path absent from the map fails the check.
     *
     * @param  array<string, ProbedResponse>  $responses  by path
     * @return array{serving: string, checks: list<array<string, mixed>>}
     */
    public function runByPath(array $responses, ?string $projectDir): array
    {
        $results = $this->resultsByPath($responses, $projectDir);
        $answered = false;
        foreach ($responses as $response) {
            if ($response->answered()) {
                $answered = true;
                break;
            }
        }

        return [
            'serving' => self::serving($answered, $results),
            'checks' => array_map(static fn (CheckResult $r): array => $r->toArray(), $results),
        ];
    }

    /**
     * @return list<CheckResult>
     */
    public function results(ProbedResponse $response, ?string $projectDir): array
    {
        return $this->resultsByPath([HealthCheck::DEFAULT_PATH => $response], $projectDir);
    }

    /**
     * Nothing is asked when nothing answered: a silent application is the port probe's finding.
     *
     * @param  array<string, ProbedResponse>  $responses  by path
     * @return list<CheckResult>
     */
    public function resultsByPath(array $responses, ?string $projectDir): array
    {
        if (! self::anyAnswered($responses)) {
            return [];
        }

        $context = $projectDir !== null && is_dir($projectDir) ? ProjectContext::at($projectDir) : null;
        $applies = $this->applicability($context);

        $results = [];
        foreach ($this->checks as $check) {
            $results[] = $this->resultFor($check, $applies, $responses, $context);
        }

        return $results;
    }

    /** @param  array<string, ProbedResponse>  $responses */
    private static function anyAnswered(array $responses): bool
    {
        foreach ($responses as $response) {
            if ($response->answered()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, bool> by reference: whether the check's `when` holds */
    private function applicability(?ProjectContext $context): array
    {
        $matcher = new PlatformMatcher(ProbeRegistry::all());

        $applies = [];
        foreach ($this->checks as $check) {
            $applies[$check->reference()] = $check->when === null
                || ($context !== null && $matcher->matches($check->when, $context));
        }

        return $applies;
    }

    /**
     * @param  array<string, bool>  $applies
     * @param  array<string, ProbedResponse>  $responses
     */
    private function resultFor(HealthCheck $check, array $applies, array $responses, ?ProjectContext $context): CheckResult
    {
        $skip = $this->honouredSkip($check, $applies);
        if ($skip !== null) {
            return CheckResult::notApplicable($check, $skip['reason'], $skip['covered_by']);
        }
        if (! $applies[$check->reference()]) {
            return CheckResult::skipped($check);
        }

        return $this->evaluate($check, $responses[$check->path()] ?? null, $context);
    }

    /**
     * The manifest's `check_skip` entry for this check, if it holds: an `error` check only while its cover is asked.
     *
     * @param  array<string, bool>  $applies  by reference: asked, not guarded out
     * @return array{check: string, reason: string, covered_by: ?string}|null
     */
    private function honouredSkip(HealthCheck $check, array $applies): ?array
    {
        $skip = $this->skips[$check->reference()] ?? null;
        if ($skip === null || $check->severity !== HealthCheck::SEVERITY_ERROR) {
            return $skip;
        }

        $cover = $skip['covered_by'];

        return $cover !== null && ($applies[$cover] ?? false) && ! isset($this->skips[$cover]) ? $skip : null;
    }

    /**
     * @param  ?ProbedResponse  $response  null when nothing was fetched for this path, unlike {@see ProbedResponse::none()}; neither passes
     */
    private function evaluate(HealthCheck $check, ?ProbedResponse $response, ?ProjectContext $context): CheckResult
    {
        if ($response === null) {
            return CheckResult::fail(
                $check,
                'The check asks about '.$check->path().', which was not fetched.',
                'The probe returned no response for this path.',
                $check->fix,
                ['url' => $check->path(), 'http_code' => null]
            );
        }

        if (self::holds($check, $response)) {
            return CheckResult::pass($check);
        }

        [$detail, $fix] = $this->explain($check, $response, $context);

        return CheckResult::fail(
            $check,
            self::interpolate($check->message, $response),
            $detail,
            $fix ?? $check->fix,
            ['url' => $response->url, 'http_code' => $response->status]
        );
    }

    /**
     * A diagnosis that throws must not cost the finding it describes.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function explain(HealthCheck $check, ProbedResponse $response, ?ProjectContext $context): array
    {
        if ($check->explain === null || $context === null) {
            return [null, null];
        }

        $explainer = ExplainerRegistry::find($check->explain);
        if ($explainer === null) {
            return [null, null];
        }

        try {
            $explained = $explainer->explain($context, $response);
        } catch (\Throwable $e) {
            return [null, null];
        }

        return [$explained['detail'] ?? null, $explained['fix'] ?? null];
    }

    /** Does the response satisfy every condition the check declared? A landing elsewhere passes. */
    private static function holds(HealthCheck $check, ProbedResponse $response): bool
    {
        if ($check->landing !== [] && ! $response->landedUnder($check->landing)) {
            return true;
        }

        $expect = $check->expect;

        if (isset($expect['status']) && ! $response->statusMatches((array) $expect['status'])) {
            return false;
        }
        if (isset($expect['status_not']) && $response->statusMatches((array) $expect['status_not'])) {
            return false;
        }
        foreach ((array) ($expect['body'] ?? []) as $needle) {
            if (! $response->bodyContains((string) $needle)) {
                return false;
            }
        }
        foreach ((array) ($expect['body_not'] ?? []) as $needle) {
            if ($response->bodyContains((string) $needle)) {
                return false;
            }
        }

        if ($check->json() !== [] && ! self::holdsJson($check, $response)) {
            return false;
        }

        return true;
    }

    /** Every member the check named must be present and equal; a body that is not JSON fails. */
    private static function holdsJson(HealthCheck $check, ProbedResponse $response): bool
    {
        $wanted = $check->json();
        $actual = $response->json();
        if ($actual === null) {
            return false;
        }

        foreach ($wanted as $key => $expected) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }
            if (! self::memberMatches($actual[$key], $expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A scalar compares by value, a list as "any of these"; numbers compare as floats,
     * and the string "true" is not the boolean.
     */
    private static function memberMatches(mixed $actual, mixed $expected): bool
    {
        if (is_array($expected)) {
            foreach ($expected as $one) {
                if (self::memberMatches($actual, $one)) {
                    return true;
                }
            }

            return false;
        }

        if (is_bool($expected)) {
            return is_bool($actual) ? $actual === $expected : false;
        }

        if (is_numeric($expected)) {
            return is_numeric($actual) && (float) $actual === (float) $expected;
        }

        return is_string($actual) && $actual === $expected;
    }

    /**
     * One word for what the application is serving: the first failed check by severity, then declaration order.
     *
     * @param  list<CheckResult>  $results
     */
    private static function serving(bool $anyAnswer, array $results): string
    {
        if (! $anyAnswer) {
            return self::SERVING_UNKNOWN;
        }

        foreach ([HealthCheck::SEVERITY_ERROR, HealthCheck::SEVERITY_WARNING, HealthCheck::SEVERITY_INFO] as $severity) {
            foreach ($results as $result) {
                if ($result->failed() && $result->check->severity === $severity && $result->check->serving !== null) {
                    return $result->check->serving;
                }
            }
        }

        return self::SERVING_OK;
    }

    private static function interpolate(string $message, ProbedResponse $response): string
    {
        return str_replace(
            ['{status}', '{url}'],
            [(string) $response->status, $response->url],
            $message
        );
    }
}
