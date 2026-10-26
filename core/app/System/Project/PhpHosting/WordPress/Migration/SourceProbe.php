<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use Closure;
use RuntimeException;

/**
 * The connection test behind `POST /wordpress/migrations/test`: the same
 * lookup a migration's first step does, synchronously, with no record and
 * nothing changed on the source (wp_admin logs in, manual_plugin asks the
 * plugin's token check). Every failure becomes an `error_type`.
 */
final class SourceProbe
{
    /** @var Closure(SourceAccess): SourceHttpClient */
    private Closure $httpFactory;

    /** @var list<SourceProbeCheck> */
    private array $checks;

    /**
     * @param  (Closure(SourceAccess): SourceHttpClient)|null  $httpFactory
     * @param  list<SourceProbeCheck>|null  $checks
     */
    public function __construct(?Closure $httpFactory = null, ?array $checks = null)
    {
        $this->httpFactory = $httpFactory
            ?? static fn (SourceAccess $access): SourceHttpClient => new CurlSourceHttpClient($access->pin());
        $this->checks = $checks ?? self::defaultChecks();
    }

    /**
     * The checks every test runs after the source is found, in order.
     *
     * @return list<SourceProbeCheck>
     */
    public static function defaultChecks(): array
    {
        return [new ImportDomainCheck];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(SourceAccess $access, TargetChoice $choice = new TargetChoice): array
    {
        $client = new SourceWordpressClient(($this->httpFactory)($access));

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
                'message' => $failure->getMessage(),
            ];
        }
    }

    private function find(SourceWordpressClient $client, SourceAccess $access): SourceSite
    {
        $resolver = new SourceSiteResolver($client);

        return $access->usesManualPlugin() ? $resolver->findPlugin($access) : $resolver->logIn($access);
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
