<?php

namespace App\Lib\Findings\Hints;

use App\Lib\Deploy\Inspect\Report\PortsReport;
use App\Lib\Findings\Finding;
use App\Lib\Project\CreateInspection;

/**
 * Findings as an answer shows them, each with the hints that act on it. Called only where a response is built, never
 * where findings are stored or reported to telemetry, so a stored deploy always shows this engine's commands.
 */
final class FindingPresenter
{
    /**
     * @param  mixed  $stored  findings, their stored arrays, or sentences stored before findings had a code
     * @param  array<string, scalar|null>  $context  what the answer is about, such as the `source` inspected
     * @return list<array<string, mixed>>
     */
    public static function present(mixed $stored, ?string $username, array $context = []): array
    {
        $findings = Finding::allStored($stored);
        $present = array_map(static fn (Finding $finding): string => $finding->code, $findings);
        $hints = new HintBuilder;
        $context += ['username' => $username, 'source' => $username];

        return array_map(static fn (Finding $finding): array => $finding->toArray() + [
            'hints' => $hints->for($finding->code, $finding->facts + $context, $present),
        ], $findings);
    }

    /**
     * @param  array<string, scalar|null>  $context
     * @return ?array<string, mixed>
     */
    public static function presentOne(mixed $stored, ?string $username, array $context = []): ?array
    {
        return self::present($stored === null ? [] : [$stored], $username, $context)[0] ?? null;
    }

    /**
     * The `problems` of a refusal, each as it was with the hints for its code; the facts beside `field`, `code` and
     * `message` fill them as a finding's do.
     *
     * @param  list<array<string, mixed>>  $problems
     * @return list<array<string, mixed>>
     */
    public static function problems(array $problems, ?string $username): array
    {
        $present = array_values(array_filter(array_map(static fn (array $problem): mixed => $problem['code'] ?? null, $problems), 'is_string'));
        $hints = new HintBuilder;
        $context = ['username' => $username, 'source' => $username];

        return array_map(static function (array $problem) use ($hints, $context, $present): array {
            $code = $problem['code'] ?? null;
            $facts = array_diff_key($problem, array_flip(['field', 'code', 'message', 'hints'])) + $context;

            return $problem + ['hints' => is_string($code) ? $hints->for($code, $facts, $present) : []];
        }, $problems);
    }

    /**
     * A project's or a task's details, with `deployment_warnings` and a failed task's `problems` presented.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function inDetails(array $details, ?string $username): array
    {
        if (array_key_exists('deployment_warnings', $details)) {
            $details['deployment_warnings'] = self::present($details['deployment_warnings'], $username);
        }
        if (is_array($details['problems'] ?? null) && array_is_list($details['problems'])) {
            $details['problems'] = self::problems(array_values(array_filter($details['problems'], 'is_array')), $username);
        }

        return $details;
    }

    /**
     * A create's `inspection`, with the hints that act on what may stop it deploying; they inspect the repository it read.
     *
     * @return ?array<string, mixed>
     */
    public static function inspection(?CreateInspection $inspection, ?string $username): ?array
    {
        $response = $inspection?->toResponse();
        $finding = $inspection?->finding();
        if ($response === null || $finding === null) {
            return $response;
        }

        return $response + ['hints' => (new HintBuilder)->for($finding->code, $finding->facts + ['username' => $username])];
    }

    /**
     * Inspect's `ports` block, with the hints that route a port the engine leaves alone or never found.
     *
     * @param  array<string, mixed>  $ports
     * @return array<string, mixed>
     */
    public static function ports(array $ports, ?string $username): array
    {
        $hints = new HintBuilder;
        if (array_key_exists('issue', $ports)) {
            $ports['issue'] = self::presentOne($ports['issue'], $username);
        }
        foreach ((array) ($ports['unrouted'] ?? []) as $i => $note) {
            if (is_array($note) && ($note['reason'] ?? null) === PortsReport::SECONDARY) {
                $ports['unrouted'][$i]['hints'] = $hints->for(PortsReport::SECONDARY_PORT, ['port' => $note['port'] ?? null, 'username' => $username]);
            }
        }

        return $ports;
    }
}
