<?php

namespace App\Lib\Deploy\Telemetry;

use App\Lib\Apis\PanelAlpha;
use App\System;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drains the spool to the ingest from the scheduler: a report goes once the server says what it did with it, and
 * after {@see Spool::MAX_ATTEMPTS} failures. A 5xx or timeout keeps a batch; a refusing 4xx drops it, logged.
 */
class TelemetryShipper
{
    /** Fields of monitoring's `IngestProblemHint` passed on to the user. */
    public const PROBLEM_FIELDS = [
        'title', 'status', 'rejected_reason', 'external_id', 'fixed_in_version',
        'stacks_hint', 'body_why', 'body_fix', 'body_log', 'body_engine',
    ];

    private System $system;

    public function __construct(?System $system = null)
    {
        $this->system = $system ?? new System;
    }

    /**
     * `pending` is read again after every spool change, so it is what is left.
     * No install id sends nothing: an empty id would merge this install with every one that cannot probe itself.
     *
     * @return array{
     *   status: string, sent: int, rejected: int, kept: int, dropped: int,
     *   bundles_sent: int, bundles_failed: int, pending: int, message: ?string
     * }
     */
    public function ship(?int $batch = null): array
    {
        $result = self::emptyResult();

        if (! Telemetry::enabled()) {
            return ['status' => 'disabled'] + $result;
        }

        $spool = Telemetry::spool();
        $result['pending'] = $spool->stats()['count'];

        $installId = Telemetry::installId();
        if ($installId === '') {
            return ['status' => 'no-install-id'] + $result;
        }

        $endpoint = Telemetry::endpoint();
        if ($endpoint === '') {
            return ['status' => 'no-endpoint'] + $result;
        }

        $items = $spool->pending($batch ?? (int) config('telemetry.batch', 25));
        if ($items === []) {
            return ['status' => 'empty'] + $result;
        }

        return $this->deliver($spool, $items, $endpoint, $installId, $result);
    }

    /**
     * @return array{
     *   status: string, sent: int, rejected: int, kept: int, dropped: int,
     *   bundles_sent: int, bundles_failed: int, pending: int, message: ?string
     * }
     */
    private static function emptyResult(): array
    {
        return [
            'status' => 'ok',
            'sent' => 0,
            'rejected' => 0,
            'kept' => 0,
            'dropped' => 0,
            'bundles_sent' => 0,
            'bundles_failed' => 0,
            'pending' => 0,
            'message' => null,
        ];
    }

    /**
     * POST `{events: [...]}`, the install identity inside every payload, as the receiver stores events one row at a
     * time. A send nothing answered says nothing about the reports, so it spends no attempts: {@see Spool::recordDeferral}.
     *
     * @param  list<array{path: string, id: string, attempts: int, report: array<string, mixed>, bundle: ?string}>  $items
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function deliver(Spool $spool, array $items, string $endpoint, string $installId, array $result): array
    {
        try {
            $response = $this->post($endpoint, ['events' => $this->events($items, $installId)]);
        } catch (\Throwable $e) {
            $this->defer($spool, $items, $result);
            $result['status'] = 'unreachable';
            $result['message'] = $e->getMessage();
            $result['pending'] = $spool->stats()['count'];

            return $result;
        }

        return $this->settleResponse($spool, $items, $response, $result);
    }

    /**
     * 404/405/408/429 are "later"; every other 4xx is "no", and retrying it fills the spool with something
     * already refused.
     *
     * @param  list<array{path: string, id: string, attempts: int, report: array<string, mixed>, bundle: ?string}>  $items
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function settleResponse(Spool $spool, array $items, Response $response, array $result): array
    {
        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            return $this->settle($spool, $items, $response->json(), $result);
        }

        if ($this->isPermanentRejection($status)) {
            return $this->drop($spool, $items, $result, $status, $response->body());
        }

        $this->keep($spool, $items, $result);
        $result['status'] = 'retry';
        $result['message'] = "HTTP {$status}";
        $result['pending'] = $spool->stats()['count'];

        return $result;
    }

    /**
     * Send one failed deploy's report straight away and return monitoring's fix for it, if any; `sent` false
     * means it still has to go through the spool.
     *
     * @param  array<string, mixed>  $report
     * @return array{sent: bool, problem: ?array<string, ?string>}
     */
    public function sendNow(array $report): array
    {
        $notSent = ['sent' => false, 'problem' => null];
        $timeout = (int) config('telemetry.failure_timeout', 5);
        $installId = Telemetry::installId();
        $endpoint = Telemetry::endpoint();
        if ($timeout <= 0 || $installId === '' || $endpoint === '') {
            return $notSent;
        }

        $events = $this->events([['report' => $report]], $installId);

        try {
            $response = $this->post($endpoint, ['events' => $events], $timeout);
        } catch (\Throwable $e) {
            Log::debug('Telemetry could not send a failure straight away: '.$e->getMessage());

            return $notSent;
        }

        if (! $response->successful()) {
            return $notSent;
        }

        $body = $response->json();
        $id = (string) ($report['id'] ?? '');
        ['accepted' => $accepted, 'rejected' => $rejected] = self::verdict($body);
        if (! in_array($id, $rejected, true) && $accepted !== null && ! in_array($id, $accepted, true)) {
            return $notSent;
        }

        return ['sent' => true, 'problem' => self::problem($body, (string) ($report['fingerprint'] ?? ''))];
    }

    /**
     * The fix for the batch's one event (`results[].problem` at index 0, for this fingerprint): known string fields,
     * capped, no control characters, as users read it. Null when it has neither a title nor a fix.
     *
     * @return array<string, ?string>|null
     */
    public static function problem(mixed $body, string $fingerprint): ?array
    {
        foreach (is_array($body) && is_array($body['results'] ?? null) ? $body['results'] : [] as $entry) {
            if (
                ! is_array($entry)
                || ($entry['index'] ?? null) !== 0
                || ! is_array($entry['problem'] ?? null)
                || ($entry['fingerprint'] ?? $fingerprint) !== $fingerprint
            ) {
                continue;
            }

            $problem = [];
            foreach (self::PROBLEM_FIELDS as $field) {
                $value = $entry['problem'][$field] ?? null;
                $problem[$field] = is_string($value) && trim($value) !== ''
                    ? mb_substr((string) preg_replace('/[^\P{C}\n\t]/u', '', $value), 0, 8000)
                    : null;
            }

            return $problem['title'] === null && $problem['body_fix'] === null ? null : $problem;
        }

        return null;
    }

    /**
     * One envelope per queued report, each carrying the same install facts.
     *
     * @param  list<array{report: array<string, mixed>}>  $items
     * @return list<array<string, mixed>>
     */
    private function events(array $items, string $installId): array
    {
        $install = ['id' => $installId] + HostFacts::envelope($this->system);

        $events = [];
        foreach ($items as $item) {
            $event = Telemetry::envelope($item['report']);
            $event['payload']['install'] = $install;
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Connecting never takes longer than the whole timeout, nor more than ten seconds.
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(string $endpoint, array $payload, ?int $timeout = null): Response
    {
        $timeout ??= (int) config('telemetry.timeout', 15);

        return Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent(),
        ] + $this->authHeader() + PanelAlpha::identityHeaders())
            ->timeout($timeout)
            ->connectTimeout(min(10, $timeout))
            ->post($endpoint, $payload);
    }

    /**
     * A 4xx, which means this payload will never be accepted, except 408 and 429 ("later") and 404 and 405: no ingest
     * at this URL yet, and dropping on those would empty every spool in the fleet before the ingest is reachable.
     */
    private function isPermanentRejection(int $status): bool
    {
        return $status >= 400
            && $status < 500
            && ! in_array($status, [404, 405, 408, 429], true);
    }

    /**
     * @param  list<array{path: string, id: string, attempts: int, report: array<string, mixed>, bundle: ?string}>  $items
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function drop(Spool $spool, array $items, array $result, int $status, string $body): array
    {
        foreach ($items as $item) {
            $spool->forget($item['path']);
            $result['dropped']++;
        }

        $result['status'] = 'rejected';
        $result['message'] = "HTTP {$status}: ".mb_substr($body, 0, 300);
        $result['pending'] = $spool->stats()['count'];
        Log::warning('Telemetry batch permanently rejected: '.$result['message']);

        return $result;
    }

    /**
     * Apply the server's per-report verdict. A 2xx naming no accepted ids accepted the whole batch:
     * holding reports it has stored would double-count every failure.
     *
     * @param  list<array{path: string, id: string, attempts: int, report: array<string, mixed>, bundle: ?string}>  $items
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function settle(Spool $spool, array $items, mixed $body, array $result): array
    {
        ['accepted' => $accepted, 'rejected' => $rejected, 'wanted' => $wanted] = self::verdict($body);

        foreach ($items as $item) {
            if (in_array($item['id'], $rejected, true)) {
                $spool->forget($item['path']);
                $result['rejected']++;

                continue;
            }

            if ($accepted !== null && ! in_array($item['id'], $accepted, true)) {
                $this->retryOrDrop($spool, $item['path'], $result);

                continue;
            }

            if (! $this->deliverBundle($item, $wanted, $result)) {
                $this->retryOrDrop($spool, $item['path'], $result);

                continue;
            }

            $spool->forget($item['path']);
            $result['sent']++;
        }

        $result['status'] = 'ok';
        $result['pending'] = $spool->stats()['count'];

        return $result;
    }

    /**
     * The server's per-report answer, untrusted, so anything but a scalar id is dropped. `accepted` stays null when
     * the server named none, telling "it took the batch" apart from "it took none of them".
     *
     * @return array{accepted: ?list<string>, rejected: list<string>, wanted: list<string>}
     */
    private static function verdict(mixed $body): array
    {
        if (! is_array($body)) {
            return ['accepted' => null, 'rejected' => [], 'wanted' => []];
        }

        $rejected = [];
        foreach (is_array($body['rejected'] ?? null) ? $body['rejected'] : [] as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : $entry;
            if (is_scalar($id)) {
                $rejected[] = (string) $id;
            }
        }

        return [
            'accepted' => is_array($body['accepted'] ?? null) ? self::ids($body['accepted']) : null,
            'rejected' => $rejected,
            'wanted' => is_array($body['request_source'] ?? null) ? self::ids($body['request_source']) : [],
        ];
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function ids(array $values): array
    {
        return array_values(array_map('strval', array_filter($values, 'is_scalar')));
    }

    /**
     * Send this report's source bundle if the server asked for it. False means the upload failed, so the report
     * is held for the next run to offer again; true means the report is done.
     *
     * @param  array{path: string, id: string, attempts: int, report: array<string, mixed>, bundle: ?string}  $item
     * @param  list<string>  $wanted
     * @param  array<string, mixed>  $result
     */
    private function deliverBundle(array $item, array $wanted, array &$result): bool
    {
        if ($item['bundle'] === null || ! is_file($item['bundle']) || ! in_array($item['id'], $wanted, true)) {
            return true;
        }

        if (! $this->uploadBundle($item['id'], $item['bundle'])) {
            $result['bundles_failed']++;

            return false;
        }

        $result['bundles_sent']++;

        return true;
    }

    /**
     * Hold a report for another attempt, or give up on it: the spool counts attempts and stops at its own limit,
     * so an undeliverable report does not sit in the outbox forever.
     *
     * @param  array<string, mixed>  $result
     */
    private function retryOrDrop(Spool $spool, string $path, array &$result): void
    {
        if ($spool->recordFailure($path)) {
            $result['kept']++;

            return;
        }

        $result['dropped']++;
    }

    /**
     * Upload one source bundle raw as application/zip to `{endpoint}/{reportId}/source`: one part, the id already in
     * the path. True when nothing is left to send, so the report is not held for it.
     */
    private function uploadBundle(string $reportId, string $bundlePath): bool
    {
        $endpoint = rtrim(Telemetry::endpoint(), '/')
            .'/'.rawurlencode($reportId).'/source';

        $handle = @fopen($bundlePath, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $response = $this->postBundle($endpoint, $handle, $bundlePath);
        } catch (\Throwable $e) {
            Log::debug("Telemetry source bundle upload failed for {$reportId}: ".$e->getMessage());

            return false;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return $this->bundleAnswered($reportId, $response->status());
    }

    /**
     * @param  resource  $handle
     */
    private function postBundle(string $endpoint, $handle, string $bundlePath): Response
    {
        return Http::withHeaders([
            'Content-Type' => 'application/zip',
            'User-Agent' => $this->userAgent(),
            'Content-Length' => (string) filesize($bundlePath),
        ] + $this->authHeader() + PanelAlpha::identityHeaders())
            ->timeout((int) config('telemetry.source_bundle.upload_timeout', 120))
            ->withBody($handle, 'application/zip')
            ->post($endpoint);
    }

    /**
     * A 2xx, or a 4xx but 408 and 429, is final. Unlike a batch, 404 and 405 are too: the server has just asked for
     * this source, so they mean it does not know the report, and dropping someone's source early is the safe side.
     */
    private function bundleAnswered(string $reportId, int $status): bool
    {
        if ($status >= 200 && $status < 300) {
            return true;
        }

        if ($status >= 400 && $status < 500 && $status !== 408 && $status !== 429) {
            Log::warning("Telemetry source bundle refused for {$reportId}: HTTP {$status}");

            return true;
        }

        return false;
    }

    /**
     * @param  list<array{path: string, id: string, attempts: int, report: array<string, mixed>}>  $items
     * @param  array<string, mixed>  $result
     */
    private function keep(Spool $spool, array $items, array &$result): void
    {
        foreach ($items as $item) {
            $this->retryOrDrop($spool, $item['path'], $result);
        }
    }

    /**
     * Hold a batch the ingest never saw, kept like a 5xx without the give-up counter: a report is abandoned only
     * once a server has looked at it and kept saying no.
     *
     * @param  list<array{path: string, id: string, attempts: int, report: array<string, mixed>, bundle: ?string}>  $items
     * @param  array<string, mixed>  $result
     */
    private function defer(Spool $spool, array $items, array &$result): void
    {
        foreach ($items as $item) {
            $spool->recordDeferral($item['path']);
            $result['kept']++;
        }
    }

    private function userAgent(): string
    {
        $version = (string) config('system.version', 'unknown');

        return "PanelAlphaEngine/{$version} (deploy-telemetry)";
    }

    /**
     * Credential for the ingest, when one is configured. The engine holds no licence key, so none is the normal case,
     * and an ingest that later wants one is a config change.
     *
     * @return array<string, string>
     */
    private function authHeader(): array
    {
        $token = trim((string) config('telemetry.token', ''));

        return $token === '' ? [] : ['Authorization' => 'Bearer '.$token];
    }
}
