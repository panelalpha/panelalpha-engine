<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use Closure;
use RuntimeException;

/**
 * The connection test behind `POST /wordpress/migrations/test`: the same
 * lookup a migration's first step does, synchronously, with no record and
 * nothing changed on the source (wp_admin logs in, manual_plugin asks the
 * plugin's token check). Every failure becomes an `error_type`. It runs
 * inside the HTTP request, so every source request together gets
 * DEADLINE_SECONDS, not the queue job's budget.
 */
final class SourceProbe
{
    /** How long one test may hold the HTTP request, and the PHP-FPM worker serving it. */
    public const DEADLINE_SECONDS = 30;

    /** @var Closure(SourceAccess): SourceHttpClient */
    private Closure $httpFactory;

    /** @var list<SourceProbeCheck> */
    private array $checks;

    /**
     * @param  (Closure(SourceAccess): SourceHttpClient)|null  $httpFactory
     * @param  list<SourceProbeCheck>|null  $checks
     */
    public function __construct(
        ?Closure $httpFactory = null,
        ?array $checks = null,
        private readonly int $deadlineSeconds = self::DEADLINE_SECONDS,
    ) {
        $this->httpFactory = $httpFactory
            ?? static fn (SourceAccess $access): SourceHttpClient => CurlSourceHttpClient::forConnectionTest($access->pin());
        $this->checks = $checks ?? self::defaultChecks();
    }

    /**
     * The checks every test runs after the source is found, in order.
     *
     * @return list<SourceProbeCheck>
     */
    public static function defaultChecks(): array
    {
        return [new TransferAddressCheck, new ImportDomainCheck];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(SourceAccess $access, TargetChoice $choice = new TargetChoice): array
    {
        $http = ($this->httpFactory)($access);
        $http->whileWaiting($this->untilDeadline());
        $client = new SourceWordpressClient($http);

        try {
            $site = $this->find($client, $access);
            $answer = [
                'ok' => true,
                'url' => $site->url,
                'login_url' => $site->loginUrl,
                'rest_mode' => $site->restMode,
                'wordpress_version' => $this->wordpressVersion($client, $site),
            ];
            foreach ($this->checks as $check) {
                $answer = array_merge($answer, $check->check($access, $site, $choice));
            }

            return $answer;
        } catch (RuntimeException $e) {
            $failure = SourceCheckFailed::from($e);

            return [
                'ok' => false,
                'error_type' => $failure->errorType(),
                'message' => $this->messageFor($failure),
            ];
        }
    }

    /** @return Closure(): bool */
    private function untilDeadline(): Closure
    {
        $deadline = microtime(true) + $this->deadlineSeconds;

        return static fn (): bool => microtime(true) < $deadline;
    }

    private function messageFor(SourceCheckFailed $failure): string
    {
        if ($failure->getPrevious() instanceof SourceRequestAborted) {
            return "The source site did not answer within {$this->deadlineSeconds} seconds.";
        }

        return $failure->getMessage();
    }

    private function find(SourceWordpressClient $client, SourceAccess $access): SourceSite
    {
        $resolver = new SourceSiteResolver($client);

        return $access->usesManualPlugin()
            ? $resolver->findPlugin($access)
            : $resolver->logIn($access, confirmAdminEmail: false);
    }

    private function wordpressVersion(SourceWordpressClient $client, SourceSite $site): ?string
    {
        try {
            return WpAdminHtml::generatorVersion($client->http()->request('GET', $site->url.'/')->body);
        } catch (RuntimeException) {
            return null;
        }
    }
}
