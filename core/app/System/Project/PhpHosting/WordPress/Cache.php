<?php

namespace App\System\Project\PhpHosting\WordPress;

use Illuminate\Support\Facades\Log;

/**
 * Named cache flush for a PHP hosting WordPress install.
 *
 * Flushes the object cache (`wp cache flush`), then, best effort, the page
 * caches of WP Super Cache, LiteSpeed Cache, W3 Total Cache, WP Rocket and
 * WP Fastest Cache through their own purge functions. A page-cache failure is
 * logged and reported, not raised; with plugins skipped there is nothing to
 * call, so that step is skipped. Goes through WpCli::runOrFail so a successful
 * flush shares the OpenLiteSpeed webserver-reload side effect of the raw
 * `cache flush` command.
 */
final class Cache
{
    private const PURGE_PAGE_CACHES = <<<'PHP'
if (function_exists('wp_cache_clear_cache')) { wp_cache_clear_cache(); }
if (has_action('litespeed_purge_all')) { do_action('litespeed_purge_all'); }
if (function_exists('w3tc_flush_all')) { w3tc_flush_all(); }
if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); }
if (has_action('wpfc_clear_all_cache')) { do_action('wpfc_clear_all_cache'); }
PHP;

    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @return array{message: string, page_caches: string}
     */
    public function flush(): array
    {
        $result = $this->cli->runOrFail(['cache', 'flush']);

        return [
            'message' => trim($result['stdout']),
            'page_caches' => $this->purgePageCaches(),
        ];
    }

    /** flushed, failed, or skipped (plugins not loaded). */
    private function purgePageCaches(): string
    {
        if ($this->cli->skipsPlugins()) {
            return 'skipped';
        }

        try {
            $result = $this->cli->run(['eval', self::PURGE_PAGE_CACHES]);
        } catch (\Exception $e) {
            $result = ['exit_code' => 1, 'stdout' => '', 'stderr' => $e->getMessage()];
        }

        if (($result['exit_code'] ?? 1) === 0) {
            return 'flushed';
        }

        Log::warning('Best-effort WordPress page cache flush failed', [
            'exit_code' => $result['exit_code'] ?? null,
            'output' => $this->cli->failureMessage($result),
        ]);

        return 'failed';
    }
}
