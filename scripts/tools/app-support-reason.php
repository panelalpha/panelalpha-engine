<?php

/**
 * Re-derive the engine's failure sentence for a batch run's captured log.
 *
 * A deploy-failed app's `deploy.log` holds the engine's *old* one-line verdict
 * as well as the build output it was derived from. When a rule or the selector
 * that feeds it is fixed, the recorded verdict is stale while the evidence is
 * not -- so the current `FailureOutput::select()` + `DeployFailureExplainer`
 * are run over the same bytes to say what the engine says today.
 *
 * This is a re-read, not a re-deploy: it can only restate the reason, never
 * change the verdict. An app that failed still failed. Use it to decide what
 * to label and what to re-test, and re-test anything whose new reason names a
 * fix that has since landed.
 *
 * Usage:
 *   php scripts/tools/app-support-reason.php <deploy.log> [<deploy.log> ...]
 *
 * Run from the repository root (it needs core/vendor/autoload.php).
 *
 * Prints one JSON object per line, in argument order:
 *   {"file": ..., "rule": ..., "message": ..., "selected_first": ...}
 */

require __DIR__ . '/../../core/vendor/autoload.php';

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\FailureOutput;

foreach (array_slice($argv, 1) as $file) {
    $out = ['file' => $file, 'rule' => null, 'message' => null, 'selected_first' => null];

    if (!is_file($file)) {
        $out['error'] = 'missing';
        echo json_encode($out), "\n";
        continue;
    }

    $log = self_batch_log_only((string) file_get_contents($file));
    $selected = FailureOutput::select($log);
    $match = DeployFailureExplainer::match($selected);

    $out['selected_first'] = explode("\n", $selected)[0] ?? null;
    $out['rule'] = $match['rule'] ?? null;
    $out['message'] = $match['message'] ?? null;

    echo json_encode($out), "\n";
}

/**
 * The engine's own output, without this harness's lines.
 *
 * A `deploy.log` is two streams concatenated: the engine's deploy log (what
 * the engine wrote, which is what it ran `select()` over) and the batch
 * runner's own `[inspect]`/`[create]`/`[deploy]`/`[delete]` notes. Feeding
 * those to `select()` invents a failure out of harness chatter -- every such
 * line is non-noise text, so an app whose real cause sat more than the window
 * back got "`[inspect] status=200 ...`" reported as its reason.
 *
 * The engine's lines are the timestamped ones (`[2026-...]`) plus its own
 * raw output; the harness's are marked with a `[lowercase]` tag at the start.
 */
function self_batch_log_only(string $log): string
{
    $kept = [];
    foreach (preg_split('/\r?\n/', $log) ?: [] as $line) {
        if (preg_match('/^\[(inspect|create|deploy|delete|health|http|orphan-cleanup)\]/', $line) === 1) {
            continue;
        }
        if (preg_match('/^=+ /', $line) === 1) {
            continue;
        }
        $kept[] = $line;
    }

    return implode("\n", $kept);
}
