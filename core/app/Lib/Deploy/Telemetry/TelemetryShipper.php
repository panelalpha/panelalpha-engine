<?php

namespace App\Lib\Deploy\Telemetry;

use App\Lib\Apis\PanelAlpha;
use App\System;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drain the spool to the ingest endpoint.
 *
 * Runs from the scheduler, never from a deploy. Everything about it is built
 * for an endpoint that may be unreachable for a week: reports are only removed
 * once the server has said what it did with them, failures back off, and a
 * report that has failed {@see Spool::MAX_ATTEMPTS} times is dropped rather
 * than retried into the heat death of the universe.
 *
 * The one distinction worth being careful about is 4xx versus 5xx. A 5xx or a
 * timeout is "come back later", so the batch is kept. A 4xx other than 408/429
 * means the server has looked at the payload and refused it, and sending the
 * same bytes again tomorrow will get the same answer — so the batch is dropped
 * and the reason is logged. Retrying a permanent rejection forever is how a
 * spool fills a disk.
 */
class TelemetryShipper
{
    private System $system;

    public function __construct(?System $system = null)
    {
        $this->system = $system ?? new System();
    }

    /**
     * @return array{
     *   status: string, sent: int, rejected: int, kept: int, dropped: int,
     *   bundles_sent: int, bundles_failed: int, pending: int, message: ?string
     * }
     */
    public function ship(?int $batch = null): array
    {
        $result = [
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

        if (!Telemetry::enabled()) {
            return ['status' => 'disabled'] + $result;
        }

        $spool = Telemetry::spool();
        // What is queued right now. Every path that goes on to change the
        // spool takes this again before returning: an operator reading
        // "sent 1 (queue: 1)" after a successful ship has been told the
        // report is still waiting, which is the opposite of what happened.
        $result['pending'] = $spool->stats()['count'];

        $installId = Telemetry::installId();
        if ($installId === '') {
            // Sending under an empty id would merge this install with every
            // other one that cannot probe its own machine.
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

        // The shared event envelope, same shape the panel emits and the
        // monitoring ingest accepts: {events: [{type, occurred_at, payload}]}.
        // Each event is self-describing because the receiver stores them one
        // row at a time -- so the install identity rides inside every payload
        // rather than once at the batch level, where it would be lost on
        // storage. The local log leaves it out: the file is already on the box
        // whose identity it would be repeating.
        try {
            $response = $this->post($endpoint, ['events' => $this->events($items, $installId)]);
        } catch (\Throwable $e) {
            // Nothing answered -- DNS, a closed socket, an ingest that is not
            // serving yet. That says nothing about the reports, so it must not
            // spend their attempts: {@see Spool::recordDeferral}.
            $this->defer($spool, $items, $result);
            $result['status'] = 'unreachable';
            $result['message'] = $e->getMessage();
            $result['pending'] = $spool->stats()['count'];

            return $result;
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            return $this->settle($spool, $items, $response->json(), $result);
        }

        // 404/405/408/429 are "later"; every other 4xx is "no", and retrying it
        // fills the spool with something already refused.
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
     * One envelope per queued report, each carrying the same install facts.
     *
     * @param list<array{path: string, id: string, attempts: int, report: array<string, mixed>, bundle: ?string}> $items
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
     * @param array<string, mixed> $payload
     */
    private function post(string $endpoint, array $payload): \Illuminate\Http\Client\Response
    {
        $timeout = (int) config('telemetry.timeout', 15);

        return Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => $this->userAgent(),
            ] + $this->authHeader() + PanelAlpha::identityHeaders())
            ->timeout($timeout)
            // Never longer than the whole budget, and never more than ten
            // seconds waiting on a socket that is not going to open.
            ->connectTimeout(min(10, $timeout))
            ->post($endpoint, $payload);
    }

    /**
     * A status that means this payload will never be accepted.
     *
     * 4xx normally means the server read the payload and refused it, so
     * sending the same bytes tomorrow earns the same answer. Four of them mean
     * something else and must not cost the reports:
     *
     *   408, 429  "later" in as many words
     *   404, 405  there is no ingest at this URL *yet*. A host serving the rest
     *             of its API while the reports route is still being built
     *             answers exactly this, and it says nothing about the payload.
     *             Dropping there would empty every spool in the fleet on the
     *             first cron run after the ingest becomes reachable.
     */
    private function isPermanentRejection(int $status): bool
    {
        return $status >= 400
            && $status < 500
            && !in_array($status, [404, 405, 408, 429], true);
    }

    /**
     * @param list<array{path: string, id: string, attempts: int, report: array<string, mixed>, bundle: ?string}> $items
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function drop(Spool $spool, array $items, array $result, int $status, string $body): array
    {
        foreach ($items as $item) {
            $spool->forget($item['path']);
            $result['dropped']++;
        }

        $result['status'] = 'rejected';
        $result['message'] = "HTTP {$status}: " . mb_substr($body, 0, 300);
        $result['pending'] = $spool->stats()['count'];
        Log::warning('Telemetry batch permanently rejected: ' . $result['message']);

        return $result;
    }

    /**
     * Apply the server's per-report verdict.
     *
     * A server that answers with nothing useful is taken at its word: 2xx with
     * no body means it accepted the batch, so the batch is cleared. Holding
     * reports the server has already stored would double-count every failure.
     *
     * @param list<array{path: string, id: string, attempts: int, report: array<string, mixed>, bundle: ?string}> $items
     * @param array<string, mixed> $result
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

            // A server that names no accepted ids has accepted the batch.
            if ($accepted !== null && !in_array($item['id'], $accepted, true)) {
                $this->retryOrDrop($spool, $item['path'], $result);
                continue;
            }

            if (!$this->deliverBundle($item, $wanted, $result)) {
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
     * The server's per-report answer, as far as it gave one.
     *
     * Every field is optional and every field is untrusted: this is a response
     * from somewhere else, so anything that is not a scalar id is dropped
     * rather than coerced. `accepted` stays null when the server did not name
     * any, which is how "it took the batch" is told apart from "it took none
     * of them".
     *
     * @return array{accepted: ?list<string>, rejected: list<string>, wanted: list<string>}
     */
    private static function verdict(mixed $body): array
    {
        if (!is_array($body)) {
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
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function ids(array $values): array
    {
        return array_values(array_map('strval', array_filter($values, 'is_scalar')));
    }

    /**
     * Send this report's source bundle, if the server asked for it.
     *
     * True when there was nothing to send or the send worked — in both cases
     * the report itself is done. False means the bundle upload failed, and the
     * report is held so the next run can offer it again: the server asked for
     * the source and has not got it.
     *
     * @param array{path: string, id: string, attempts: int, report: array<string, mixed>, bundle: ?string} $item
     * @param list<string> $wanted
     * @param array<string, mixed> $result
     */
    private function deliverBundle(array $item, array $wanted, array &$result): bool
    {
        if ($item['bundle'] === null || !in_array($item['id'], $wanted, true)) {
            return true;
        }

        if (!$this->uploadBundle($item['id'], $item['bundle'])) {
            $result['bundles_failed']++;

            return false;
        }

        $result['bundles_sent']++;

        return true;
    }

    /**
     * Hold a report for another attempt, or give up on it.
     *
     * The spool decides: it counts attempts and stops at its own limit, so a
     * report that can never be delivered does not sit in the outbox forever.
     *
     * @param array<string, mixed> $result
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
     * Upload one source bundle to `{endpoint}/{reportId}/source`.
     *
     * Sent raw as application/zip rather than multipart: there is exactly one
     * part, the report id is already in the path, and streaming a 25 MB file
     * through a multipart encoder buys nothing.
     */
    private function uploadBundle(string $reportId, string $bundlePath): bool
    {
        if (!is_file($bundlePath)) {
            return true; // nothing to send; do not hold the report for it
        }

        $endpoint = rtrim(Telemetry::endpoint(), '/')
            . '/' . rawurlencode($reportId) . '/source';

        $handle = @fopen($bundlePath, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                    'Content-Type' => 'application/zip',
                    'User-Agent' => $this->userAgent(),
                    'Content-Length' => (string) filesize($bundlePath),
                ] + PanelAlpha::identityHeaders())
                ->timeout((int) config('telemetry.source_bundle.upload_timeout', 120))
                ->withBody($handle, 'application/zip')
                ->post($endpoint);
        } catch (\Throwable $e) {
            Log::debug("Telemetry source bundle upload failed for {$reportId}: " . $e->getMessage());

            return false;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            return true;
        }

        // Same rule as the report batch: a 4xx is the server's final answer, so
        // stop trying rather than holding a copy of someone's source forever.
        if ($status >= 400 && $status < 500 && $status !== 408 && $status !== 429) {
            Log::warning("Telemetry source bundle refused for {$reportId}: HTTP {$status}");

            return true;
        }

        return false;
    }

    /**
     * @param list<array{path: string, id: string, attempts: int, report: array<string, mixed>}> $items
     * @param array<string, mixed> $result
     */
    private function keep(Spool $spool, array $items, array &$result): void
    {
        foreach ($items as $item) {
            $this->retryOrDrop($spool, $item['path'], $result);
        }
    }

    /**
     * Hold a batch the ingest never saw.
     *
     * Kept like a 5xx, but without the give-up counter: a report is only
     * abandoned once a server has looked at it and kept saying no. An engine
     * whose ingest does not resolve is otherwise the worst case there is -- it
     * would lose every report it has inside a couple of hours, which is
     * exactly when the operator most wants them.
     *
     * @param list<array{path: string, id: string, attempts: int, report: array<string, mixed>, bundle: ?string}> $items
     * @param array<string, mixed> $result
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
     * Credential for the ingest, when one is configured.
     *
     * The engine holds no licence key -- unlike the panel, which authenticates
     * to monitoring with one. Sending nothing is therefore the normal case, and
     * this exists so that an ingest which later wants a credential is a config
     * change rather than a code change.
     *
     * @return array<string, string>
     */
    private function authHeader(): array
    {
        $token = trim((string) config('telemetry.token', ''));

        return $token === '' ? [] : ['Authorization' => 'Bearer ' . $token];
    }
}
