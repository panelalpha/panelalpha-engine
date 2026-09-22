<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Compose\ComposePlaceholders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ComposePlaceholdersTest extends TestCase
{
    private const SEED = 'test-seed-not-a-real-app-key';

    /** The compose file docmost ships in its repository, verbatim. */
    private function docmostCompose(): array
    {
        return Yaml::parse(<<<'YAML'
services:
  docmost:
    image: docmost/docmost:latest
    depends_on:
      - db
      - redis
    environment:
      APP_URL: 'http://localhost:3000'
      APP_SECRET: 'REPLACE_WITH_LONG_SECRET'
      DATABASE_URL: 'postgresql://docmost:STRONG_DB_PASSWORD@db:5432/docmost?schema=public'
      REDIS_URL: 'redis://redis:6379'
    ports:
      - "3000:3000"
  db:
    image: postgres:18
    environment:
      POSTGRES_DB: docmost
      POSTGRES_USER: docmost
      POSTGRES_PASSWORD: STRONG_DB_PASSWORD
  redis:
    image: redis:8
YAML);
    }

    public function test_it_fills_the_placeholder_secret_the_app_validates(): void
    {
        $result = ComposePlaceholders::fill($this->docmostCompose(), self::SEED);
        $secret = $result['compose']['services']['docmost']['environment']['APP_SECRET'];

        $this->assertNotSame('REPLACE_WITH_LONG_SECRET', $secret);
        $this->assertGreaterThanOrEqual(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $secret);
        $this->assertContains('APP_SECRET', $result['secrets']);
    }

    public function test_the_database_password_stays_the_same_on_both_sides(): void
    {
        $services = ComposePlaceholders::fill($this->docmostCompose(), self::SEED)['compose']['services'];
        $password = $services['db']['environment']['POSTGRES_PASSWORD'];
        $url = $services['docmost']['environment']['DATABASE_URL'];

        $this->assertNotSame('STRONG_DB_PASSWORD', $password);
        $this->assertStringNotContainsString('STRONG_DB_PASSWORD', $url);
        $this->assertSame(
            'postgresql://docmost:' . $password . '@db:5432/docmost?schema=public',
            $url
        );
    }

    public function test_the_app_secret_and_the_database_password_are_different(): void
    {
        $services = ComposePlaceholders::fill($this->docmostCompose(), self::SEED)['compose']['services'];

        $this->assertNotSame(
            $services['docmost']['environment']['APP_SECRET'],
            $services['db']['environment']['POSTGRES_PASSWORD']
        );
    }

    public function test_generated_values_are_stable_across_rebuilds(): void
    {
        $first = ComposePlaceholders::fill($this->docmostCompose(), self::SEED)['compose'];
        $second = ComposePlaceholders::fill($this->docmostCompose(), self::SEED)['compose'];

        $this->assertSame($first, $second);
    }

    public function test_generated_values_differ_between_accounts(): void
    {
        $a = ComposePlaceholders::fill($this->docmostCompose(), 'account-a')['compose'];
        $b = ComposePlaceholders::fill($this->docmostCompose(), 'account-b')['compose'];

        $this->assertNotSame(
            $a['services']['docmost']['environment']['APP_SECRET'],
            $b['services']['docmost']['environment']['APP_SECRET']
        );
    }

    public function test_it_points_the_app_url_at_the_public_address(): void
    {
        $result = ComposePlaceholders::fill(
            $this->docmostCompose(),
            self::SEED,
            'https://notes.apps.example.test/'
        );

        $this->assertSame(
            'https://notes.apps.example.test',
            $result['compose']['services']['docmost']['environment']['APP_URL']
        );
        $this->assertContains('APP_URL', $result['urls']);
    }

    public function test_it_leaves_service_to_service_urls_alone(): void
    {
        $result = ComposePlaceholders::fill(
            $this->docmostCompose(),
            self::SEED,
            'https://docmost.example.test'
        );

        $this->assertSame(
            'redis://redis:6379',
            $result['compose']['services']['docmost']['environment']['REDIS_URL']
        );
    }

    public function test_it_does_not_rewrite_upstash_style_localhost_rest_urls(): void
    {
        $compose = ['services' => ['app' => ['environment' => [
            'APP_URL' => 'http://localhost:3000',
            'UPSTASH_REDIS_REST_URL' => 'http://localhost:8079',
        ]]]];

        $result = ComposePlaceholders::fill($compose, self::SEED, 'https://app.example.test');
        $env = $result['compose']['services']['app']['environment'];

        $this->assertSame('https://app.example.test', $env['APP_URL']);
        $this->assertSame('http://localhost:8079', $env['UPSTASH_REDIS_REST_URL']);
    }

    /** engine#236: a localhost placeholder is rewritten on any port, not just an allowlist. */
    public function test_it_rewrites_a_localhost_url_on_a_non_allowlisted_port(): void
    {
        $compose = ['services' => ['fittrackee' => ['environment' => [
            'UI_URL' => 'http://localhost:5000',
        ]]]];

        $result = ComposePlaceholders::fill($compose, self::SEED, 'https://fittrackee.example.test');
        $env = $result['compose']['services']['fittrackee']['environment'];

        $this->assertSame('https://fittrackee.example.test', $env['UI_URL']);
        $this->assertContains('UI_URL', $result['urls']);
    }

    /**
     * Dropping the port allowlist took away the only thing catching a sidecar
     * whose key the datastore pattern did not name. These are the keys that
     * fell through: each one's localhost address is the sidecar's, and
     * rewriting it points the app at its own website.
     *
     */
    #[DataProvider('sidecarKeys')]
    public function test_a_sidecar_address_is_never_rewritten(string $key, string $value): void
    {
        $compose = ['services' => ['app' => ['environment' => [
            'APP_URL' => 'http://localhost:8080',
            $key => $value,
        ]]]];

        $result = ComposePlaceholders::fill($compose, self::SEED, 'https://app.example.test');
        $env = $result['compose']['services']['app']['environment'];

        $this->assertSame($value, $env[$key]);
        $this->assertNotContains($key, $result['urls']);
        // The app's own URL is still rewritten alongside it.
        $this->assertSame('https://app.example.test', $env['APP_URL']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sidecarKeys(): array
    {
        return [
            // The key Meilisearch ships. A trailing-underscore pattern caught
            // MEILI_URL and missed this one; :7700 covered for it.
            'meilisearch' => ['MEILISEARCH_URL', 'http://localhost:7700'],
            'meili' => ['MEILI_URL', 'http://localhost:7700'],
            'elasticsearch' => ['ELASTICSEARCH_URL', 'http://localhost:9200'],
            'opensearch' => ['OPENSEARCH_URL', 'http://localhost:9200'],
            'solr' => ['SOLR_URL', 'http://localhost:8983'],
            'clickhouse' => ['CLICKHOUSE_URL', 'http://localhost:8123'],
            's3' => ['S3_ENDPOINT', 'http://localhost:9000'],
            'minio' => ['MINIO_ENDPOINT', 'http://localhost:9000'],
            'prefixed s3' => ['AWS_S3_ENDPOINT', 'http://localhost:9000'],
            'qdrant' => ['QDRANT_URL', 'http://localhost:6333'],
            'ollama' => ['OLLAMA_URL', 'http://localhost:11434'],
            'mail catcher' => ['MAIL_URL', 'http://localhost:8025'],
            'smtp' => ['SMTP_ENDPOINT', 'http://localhost:1025'],
            'typesense' => ['TYPESENSE_URL', 'http://localhost:8108'],
            'database' => ['DATABASE_URL', 'http://localhost:5432'],
        ];
    }

    /**
     * The other half of the pattern, and the one that would catch it growing
     * too greedy: an app's own address is still rewritten, whatever the port.
     * WEBMAIL_URL is the near miss -- it contains MAIL, but a webmail app's
     * own URL is the site, not a mail server.
     *
     */
    #[DataProvider('siteKeys')]
    public function test_the_app_s_own_url_is_still_rewritten(string $key): void
    {
        $compose = ['services' => ['app' => ['environment' => [
            $key => 'http://localhost:7700',
        ]]]];

        $result = ComposePlaceholders::fill($compose, self::SEED, 'https://app.example.test');

        $this->assertSame('https://app.example.test', $result['compose']['services']['app']['environment'][$key]);
        $this->assertContains($key, $result['urls']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function siteKeys(): array
    {
        return [
            'app' => ['APP_URL'],
            'ui' => ['UI_URL'],
            'public' => ['PUBLIC_URL'],
            'site' => ['SITE_URL'],
            'frontend' => ['FRONTEND_URL'],
            'nextauth' => ['NEXTAUTH_URL'],
            'vite api' => ['VITE_API_URL'],
            'webhook' => ['WEBHOOK_URL'],
            'base' => ['BASE_URL'],
            'api endpoint' => ['API_ENDPOINT'],
            'cors origin' => ['CORS_ORIGIN'],
            'cookie domain' => ['COOKIE_DOMAIN'],
            'webmail' => ['WEBMAIL_URL'],
        ];
    }

    public function test_a_real_secret_is_never_replaced(): void
    {
        $compose = ['services' => ['app' => ['environment' => [
            'APP_SECRET' => 'f3a91c0b7d2e4856aa10bd93cf7e2210',
            'STRIPE_API_KEY' => 'sk_live_51Hxyz09ABCdefGHI',
            'TEST_KEY' => 'testKey_9f3b2a11',
        ]]]];

        $result = ComposePlaceholders::fill($compose, self::SEED);

        $this->assertSame($compose, $result['compose']);
        $this->assertSame([], $result['secrets']);
    }

    public function test_a_placeholder_outside_a_credential_is_left_alone(): void
    {
        $compose = ['services' => ['app' => ['environment' => [
            'APP_NAME' => 'CHANGE_ME',
            'DEFAULT_ADMIN_EMAIL' => 'your@example.test',
        ]]]];

        $this->assertSame($compose, ComposePlaceholders::fill($compose, self::SEED)['compose']);
    }

    public function test_it_handles_the_list_form_of_environment(): void
    {
        $compose = ['services' => ['app' => ['environment' => [
            'SECRET_KEY=CHANGEME',
            'DEBUG=false',
            'HOME',
        ]]]];

        $env = ComposePlaceholders::fill($compose, self::SEED)['compose']['services']['app']['environment'];

        $this->assertStringStartsWith('SECRET_KEY=', $env[0]);
        $this->assertStringNotContainsString('CHANGEME', $env[0]);
        $this->assertSame('DEBUG=false', $env[1]);
        $this->assertSame('HOME', $env[2]);
    }

    public function test_a_short_placeholder_word_is_not_substituted_inside_other_values(): void
    {
        $compose = ['services' => ['app' => ['environment' => [
            'DB_PASSWORD' => 'secret',
            'APP_TAGLINE' => 'keep it secret, keep it safe',
        ]]]];

        $result = ComposePlaceholders::fill($compose, self::SEED)['compose']['services']['app']['environment'];

        $this->assertNotSame('secret', $result['DB_PASSWORD']);
        $this->assertSame('keep it secret, keep it safe', $result['APP_TAGLINE']);
    }

    public function test_it_fills_a_password_that_only_exists_inside_a_connection_string(): void
    {
        $compose = ['services' => ['app' => ['environment' => [
            'DATABASE_URL' => 'mysql://app:CHANGE_THIS_PASSWORD@db:3306/app',
        ]]]];

        $url = ComposePlaceholders::fill($compose, self::SEED)['compose']['services']['app']['environment']['DATABASE_URL'];

        $this->assertStringNotContainsString('CHANGE_THIS_PASSWORD', $url);
        $this->assertMatchesRegularExpression('#^mysql://app:[0-9a-f]{48}@db:3306/app$#', $url);
    }

    public function test_a_compose_without_services_is_returned_untouched(): void
    {
        $this->assertSame(['version' => '3'], ComposePlaceholders::fill(['version' => '3'], self::SEED)['compose']);
    }

    public function test_placeholder_recognition(): void
    {
        foreach ([
            'REPLACE_WITH_LONG_SECRET', 'STRONG_DB_PASSWORD', 'changeme', 'CHANGE_ME',
            'your-secret-here', '<your-api-key>', '{{ secret }}', 'xxxxxxxx',
            'generate-a-random-string', 'PUT_YOUR_TOKEN_HERE', 'password',
        ] as $value) {
            $this->assertTrue(ComposePlaceholders::isPlaceholder($value), $value);
        }

        foreach ([
            'f3a91c0b7d2e4856aa10bd93cf7e2210', 'sk_live_51Hxyz09ABCdefGHI',
            'testKey_9f3b2a11', 'passwordless-auth', '', 'p4ssw0rd!x9Q',
        ] as $value) {
            $this->assertFalse(ComposePlaceholders::isPlaceholder($value), $value);
        }
    }

    /**
     * Compose's fail-closed form. RSS Monster (#147) and Etherpad (#116) both
     * died before a container existed, because nothing supplies a value and
     * `docker compose up` refuses to interpolate.
     */
    public function test_a_required_secret_variable_is_filled(): void
    {
        $compose = ['services' => ['app' => ['environment' => [
            'JWT_SECRET' => '${JWT_SECRET:?JWT_SECRET must be set}',
            'FEVER_CREDENTIAL_SECRET' => '${FEVER_CREDENTIAL_SECRET:?FEVER_CREDENTIAL_SECRET must be set}',
        ]]]];

        $filled = ComposePlaceholders::fill($compose, 'seed')['compose'];
        $env = $filled['services']['app']['environment'];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $env['JWT_SECRET']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $env['FEVER_CREDENTIAL_SECRET']);
        $this->assertNotSame($env['JWT_SECRET'], $env['FEVER_CREDENTIAL_SECRET']);
    }

    /**
     * Etherpad reads one postgres password from both the app and the database.
     * Deriving from the variable rather than the key is what keeps them equal.
     */
    public function test_one_variable_read_by_two_services_gets_one_value(): void
    {
        $expr = '${DOCKER_COMPOSE_POSTGRES_PASSWORD:?Set it to a strong value}';
        $compose = ['services' => [
            'app' => ['environment' => ['DB_PASS' => $expr]],
            'postgres' => ['environment' => ['POSTGRES_PASSWORD' => $expr]],
        ]];

        $filled = ComposePlaceholders::fill($compose, 'seed')['compose'];

        $this->assertSame(
            $filled['services']['app']['environment']['DB_PASS'],
            $filled['services']['postgres']['environment']['POSTGRES_PASSWORD']
        );
    }

    /** Stable across rebuilds, or a redeploy locks the app out of its own data. */
    public function test_a_required_secret_is_stable_for_a_seed(): void
    {
        $compose = ['services' => ['app' => ['environment' => ['JWT_SECRET' => '${JWT_SECRET:?set me}']]]];

        $first = ComposePlaceholders::fill($compose, 'seed')['compose'];
        $second = ComposePlaceholders::fill($compose, 'seed')['compose'];
        $other = ComposePlaceholders::fill($compose, 'different-account')['compose'];

        $this->assertSame(
            $first['services']['app']['environment']['JWT_SECRET'],
            $second['services']['app']['environment']['JWT_SECRET']
        );
        $this->assertNotSame(
            $first['services']['app']['environment']['JWT_SECRET'],
            $other['services']['app']['environment']['JWT_SECRET']
        );
    }

    /**
     * A required variable naming a host or a port is not something to invent.
     * Letting the app say what it needs beats starting it pointed at nowhere.
     */
    public function test_a_required_variable_that_is_not_a_credential_is_left_alone(): void
    {
        $compose = ['services' => ['app' => ['environment' => [
            'DB_HOST' => '${DB_HOST:?set the database host}',
            'PORT' => '${PORT:?set the port}',
        ]]]];

        $filled = ComposePlaceholders::fill($compose, 'seed')['compose'];

        $this->assertSame('${DB_HOST:?set the database host}', $filled['services']['app']['environment']['DB_HOST']);
        $this->assertSame('${PORT:?set the port}', $filled['services']['app']['environment']['PORT']);
    }

    /** A default is the project answering for itself; only `:?` has no answer. */
    public function test_a_variable_with_a_default_is_left_alone(): void
    {
        $compose = ['services' => ['app' => ['environment' => [
            'JWT_SECRET' => '${JWT_SECRET:-devsecret}',
            'API_KEY' => '${API_KEY}',
        ]]]];

        $filled = ComposePlaceholders::fill($compose, 'seed')['compose'];

        $this->assertSame('${JWT_SECRET:-devsecret}', $filled['services']['app']['environment']['JWT_SECRET']);
        $this->assertSame('${API_KEY}', $filled['services']['app']['environment']['API_KEY']);
    }

    /** A variable inside a URL is a different problem; do not half-rewrite it. */
    public function test_a_variable_embedded_in_a_larger_value_is_left_alone(): void
    {
        $url = 'postgresql://app:${DB_PASSWORD:?set it}@db/app';
        $compose = ['services' => ['app' => ['environment' => ['DATABASE_URL' => $url]]]];

        $filled = ComposePlaceholders::fill($compose, 'seed')['compose'];

        $this->assertSame($url, $filled['services']['app']['environment']['DATABASE_URL']);
    }

    public function test_a_filled_required_secret_is_reported(): void
    {
        $compose = ['services' => ['app' => ['environment' => ['JWT_SECRET' => '${JWT_SECRET:?set me}']]]];

        $this->assertSame(['JWT_SECRET'], ComposePlaceholders::fill($compose, 'seed')['secrets']);
    }

    /** Planka ships `SECRET_KEY: notsecretkey`; every copy would sign with it. */
    private function plankaCompose(array $env = []): array
    {
        return ['services' => ['planka' => ['image' => 'ghcr.io/plankanban/planka', 'environment' => $env + [
            'BASE_URL' => 'https://planka.test',
            'SECRET_KEY' => 'notsecretkey',
        ]]]];
    }

    public function test_a_published_placeholder_secret_is_replaced_and_stable_per_seed(): void
    {
        $first = ComposePlaceholders::fill($this->plankaCompose(), self::SEED);
        $second = ComposePlaceholders::fill($this->plankaCompose(), self::SEED);
        $other = ComposePlaceholders::fill($this->plankaCompose(), 'another-account');
        $key = $first['compose']['services']['planka']['environment']['SECRET_KEY'];

        $this->assertNotSame('notsecretkey', $key);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $key);
        $this->assertSame($key, $second['compose']['services']['planka']['environment']['SECRET_KEY']);
        $this->assertNotSame($key, $other['compose']['services']['planka']['environment']['SECRET_KEY']);
        $this->assertSame(['SECRET_KEY'], $first['published']);
    }

    public function test_the_accounts_own_value_wins_over_a_generated_one(): void
    {
        $result = ComposePlaceholders::fill($this->plankaCompose(), self::SEED, null, ['SECRET_KEY' => 'mine']);

        $this->assertSame('mine', $result['compose']['services']['planka']['environment']['SECRET_KEY']);
        $this->assertSame([], $result['published']);
    }

    public function test_third_party_credentials_and_real_secrets_stay_as_written(): void
    {
        $real = '8f3a9c1e5b7d2f4a6c8e0b1d3f5a7c9e1b3d5f7a';
        $env = ComposePlaceholders::fill(
            $this->plankaCompose(['STRIPE_API_KEY' => 'notsecretkey', 'JWT_SECRET' => $real]),
            self::SEED
        )['compose']['services']['planka']['environment'];

        $this->assertSame('notsecretkey', $env['STRIPE_API_KEY']);
        $this->assertSame($real, $env['JWT_SECRET']);
        $this->assertFalse(ComposePlaceholders::isPublishedSecret('STRIPE_API_KEY', 'changeme'));
        $this->assertFalse(ComposePlaceholders::isPublishedSecret('AWS_SECRET_ACCESS_KEY', 'changeme'));
        $this->assertFalse(ComposePlaceholders::isPublishedSecret('SECRET_KEY', $real));
    }

    public function test_the_value_list_is_exact(): void
    {
        foreach (['"changeme"', ' CHANGE-ME ', 'xxxxxx', 'django-insecure-abc123', 'Your_Secret_Key'] as $value) {
            $this->assertTrue(ComposePlaceholders::isPublishedSecret('SECRET_KEY', $value), $value);
        }
        foreach (['', 'changemeplease', 'secretive', 'x'] as $value) {
            $this->assertFalse(ComposePlaceholders::isPublishedSecret('SECRET_KEY', $value), $value);
        }
        $this->assertTrue(ComposePlaceholders::isPublishedSecret('SESSION_KEY', 'todo'));
        $this->assertFalse(ComposePlaceholders::isPublishedSecret('LICENSE_KEY', 'changeme'));
    }

    /** Laravel only boots on base64:<32 bytes>, so a published APP_KEY gets that shape. */
    public function test_a_published_app_key_gets_a_valid_laravel_key(): void
    {
        $key = ComposePlaceholders::fill(
            ['services' => ['app' => ['environment' => ['APP_KEY' => 'changeme']]]],
            self::SEED
        )['compose']['services']['app']['environment']['APP_KEY'];

        $this->assertStringStartsWith('base64:', $key);
        $this->assertSame(32, strlen((string) base64_decode(substr($key, 7), true)));
    }
}
