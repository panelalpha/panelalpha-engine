<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\EnvFile;
use PHPUnit\Framework\TestCase;

class EnvFileTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/envfile-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function test_an_env_file_is_normalised_to_utf8(): void
    {
        $text = "# comment\r\nPORT=3000\r\nNAME=Zażółć\r\n";

        $this->assertSame($text, EnvFile::asUtf8("\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')));
        $this->assertSame($text, EnvFile::asUtf8("\xFE\xFF" . mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')));
        $this->assertSame($text, EnvFile::asUtf8("\xEF\xBB\xBF" . $text));
        $this->assertSame($text, EnvFile::asUtf8($text));
        // UTF-16 with no BOM, or any other NUL-bearing bytes: not an env file.
        $this->assertNull(EnvFile::asUtf8(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')));
    }

    public function test_nested_env_example_copies_api_and_skips_existing_file(): void
    {
        mkdir($this->tmpDir . '/api', 0777, true);
        mkdir($this->tmpDir . '/console', 0777, true);
        file_put_contents($this->tmpDir . '/api/.env.example', "APP_KEY=\n");
        file_put_contents($this->tmpDir . '/console/.env.example', "FOO=1\n");
        file_put_contents($this->tmpDir . '/console/.env', "FOO=2\n");
        file_put_contents($this->tmpDir . '/.env.example', "ROOT=1\n");

        $copies = EnvFile::nestedEnvExampleCopies($this->tmpDir);
        $relative = array_column($copies, 'relative');
        sort($relative);

        $this->assertSame(['.env', 'api/.env'], $relative);
    }

    public function test_nested_env_sample_is_copied_when_there_is_no_env_example(): void
    {
        mkdir($this->tmpDir . '/api', 0777, true);
        file_put_contents($this->tmpDir . '/api/.env.sample', "API=1\n");

        $copies = EnvFile::nestedEnvExampleCopies($this->tmpDir);

        $this->assertSame([$this->tmpDir . '/api/.env.sample'], array_column($copies, 'example'));
        $this->assertSame(['api/.env'], array_column($copies, 'relative'));
    }

    public function test_env_local_from_example_and_from_env_when_mentioned(): void
    {
        file_put_contents($this->tmpDir . '/.env.local.example', "LOCAL=1\n");
        $copies = EnvFile::nestedEnvExampleCopies($this->tmpDir);
        $this->assertSame(['.env.local'], array_column($copies, 'relative'));

        unlink($this->tmpDir . '/.env.local.example');
        file_put_contents($this->tmpDir . '/.env', "ROOT=1\n");
        file_put_contents($this->tmpDir . '/package.json', json_encode([
            'scripts' => ['dev' => 'bun --env-file=.env.local next dev'],
        ]));

        $copies = EnvFile::envLocalCopies($this->tmpDir);
        $this->assertCount(1, $copies);
        $this->assertSame('.env.local', array_values($copies)[0]['relative']);
        $this->assertSame($this->tmpDir . '/.env', array_values($copies)[0]['example']);
    }

    public function test_nested_env_example_treats_directory_dest_as_missing(): void
    {
        mkdir($this->tmpDir . '/api', 0777, true);
        mkdir($this->tmpDir . '/api/.env', 0777, true);
        file_put_contents($this->tmpDir . '/api/.env.example', "APP_KEY=\n");

        $copies = EnvFile::nestedEnvExampleCopies($this->tmpDir);

        $this->assertCount(1, $copies);
        $this->assertSame('api/.env', $copies[0]['relative']);
    }

    public function test_nested_env_example_copies_two_levels_deep(): void
    {
        mkdir($this->tmpDir . '/apps/api', 0777, true);
        file_put_contents($this->tmpDir . '/.env.example', "ROOT=1\n");
        file_put_contents($this->tmpDir . '/apps/api/.env.example', "API=1\n");

        $copies = EnvFile::nestedEnvExampleCopies($this->tmpDir);
        $relative = array_column($copies, 'relative');
        sort($relative);

        $this->assertSame(['.env', 'apps/api/.env'], $relative);
        $byRel = [];
        foreach ($copies as $copy) {
            $byRel[$copy['relative']] = $copy;
        }
        $this->assertSame($this->tmpDir . '/apps/api/.env.example', $byRel['apps/api/.env']['example']);
    }

    public function test_nested_env_example_skips_node_modules(): void
    {
        mkdir($this->tmpDir . '/node_modules/pkg', 0777, true);
        file_put_contents($this->tmpDir . '/node_modules/pkg/.env.example', "SKIP=1\n");

        $this->assertSame([], EnvFile::nestedEnvExampleCopies($this->tmpDir));
    }

    public function test_compose_env_file_copies_from_root_example_when_sibling_missing(): void
    {
        mkdir($this->tmpDir . '/apps/api', 0777, true);
        file_put_contents($this->tmpDir . '/.env.example', "ROOT=1\n");
        file_put_contents(
            $this->tmpDir . '/docker-compose.yml',
            "services:\n  api:\n    image: alpine\n    env_file:\n      - ./apps/api/.env\n"
        );

        $copies = EnvFile::nestedEnvExampleCopies($this->tmpDir);
        $byRel = [];
        foreach ($copies as $copy) {
            $byRel[$copy['relative']] = $copy;
        }

        $this->assertArrayHasKey('apps/api/.env', $byRel);
        $this->assertSame($this->tmpDir . '/.env.example', $byRel['apps/api/.env']['example']);
    }

    public function test_compose_env_file_creates_an_empty_file_when_the_repo_has_no_example(): void
    {
        file_put_contents(
            $this->tmpDir . '/docker-compose.yml',
            "services:\n  api:\n    image: alpine\n    env_file: .env\n"
        );

        $copies = EnvFile::nestedEnvExampleCopies($this->tmpDir);

        $this->assertCount(1, $copies);
        $this->assertSame('.env', $copies[0]['relative']);
        $this->assertSame('', $copies[0]['example']);
        $this->assertSame(
            ['.env'],
            EnvFile::composeEnvFileRelativePathsFromYaml(
                (string) file_get_contents($this->tmpDir . '/docker-compose.yml')
            )
        );
    }

    public function test_compose_env_file_skips_existing_dest(): void
    {
        mkdir($this->tmpDir . '/apps/api', 0777, true);
        file_put_contents($this->tmpDir . '/apps/api/.env', "EXISTING=1\n");
        file_put_contents(
            $this->tmpDir . '/docker-compose.yml',
            "services:\n  api:\n    image: alpine\n    env_file:\n      - ./apps/api/.env\n"
        );

        $this->assertSame([], EnvFile::nestedEnvExampleCopies($this->tmpDir));
    }

    public function test_database_settings_prefer_env_over_example(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "DB_CONNECTION=sqlite\nDB_DATABASE=demo\n");
        file_put_contents(
            $this->tmpDir . '/.env',
            "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_DATABASE=laravel-crm\nDB_USERNAME=root\nDB_PASSWORD=\n"
        );

        $this->assertSame(
            [
                'connection' => 'mysql',
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'laravel-crm',
                'username' => 'root',
                'password' => '',
            ],
            EnvFile::databaseSettings($this->tmpDir)
        );
    }

    public function test_database_settings_fall_back_to_example(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "DB_CONNECTION=pgsql\nDB_PORT=5432\n");

        $settings = EnvFile::databaseSettings($this->tmpDir);
        $this->assertSame('pgsql', $settings['connection']);
        $this->assertSame('5432', $settings['port']);
    }

    /**
     * Servas's prepare hook writes a 0600 `.env` choosing SQLite. Read as the
     * engine's user it was invisible and `.env.example`'s mysql won, which
     * added a MariaDB sidecar the app never used.
     */
    public function test_database_settings_read_env_through_the_given_reader(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\n");
        file_put_contents($this->tmpDir . '/.env', "DB_CONNECTION=sqlite\n");
        chmod($this->tmpDir . '/.env', 0000);
        if (is_readable($this->tmpDir . '/.env')) {
            $this->markTestSkipped('running as root, a 0000 file is still readable');
        }

        // The engine's own view: the unreadable .env reads as absent.
        $this->assertSame('mysql', EnvFile::databaseSettings($this->tmpDir)['connection']);

        $asAccount = fn (string $path): ?string => match (basename($path)) {
            '.env' => "DB_CONNECTION=sqlite\n",
            '.env.example' => "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\n",
            default => null,
        };
        $this->assertSame('sqlite', EnvFile::databaseSettings($this->tmpDir, $asAccount)['connection']);
        chmod($this->tmpDir . '/.env', 0644);
    }

    public function test_parse_classifies_each_line_kind(): void
    {
        $rows = EnvFile::parse("# a comment\n\nAPP_ENV=production\nexport TOKEN=abc\nnot a variable\n");

        $this->assertSame(
            [
                ['type' => 'comment', 'text' => '# a comment'],
                ['type' => 'blank'],
                ['type' => 'variable', 'key' => 'APP_ENV', 'value' => 'production'],
                ['type' => 'variable', 'key' => 'TOKEN', 'value' => 'abc'],
                ['type' => 'comment', 'text' => 'not a variable'],
            ],
            $rows
        );
    }

    public function test_parse_unquotes_values_and_strips_trailing_comments(): void
    {
        $rows = EnvFile::parse(
            'PLAIN=hello world # trailing' . "\n"
            . 'SINGLE=\'raw \n stays\'' . "\n"
            . 'DOUBLE="line\nbreak"' . "\n"
            . 'EMPTY=' . "\n"
        );
        $values = array_column($rows, 'value', 'key');

        $this->assertSame('hello world', $values['PLAIN']);
        $this->assertSame('raw \n stays', $values['SINGLE'], 'single quotes are literal');
        $this->assertSame("line\nbreak", $values['DOUBLE'], 'double quotes expand escapes');
        $this->assertSame('', $values['EMPTY']);
    }

    public function test_parse_ignores_spaces_around_the_equals_sign(): void
    {
        $rows = EnvFile::parse("HATSU_LOG = \"info,tokio::net=debug\"\nHATSU_DOMAIN =  hatsu.local\nSINGLE = 'a b'\n");
        $values = array_column($rows, 'value', 'key');

        $this->assertSame('info,tokio::net=debug', $values['HATSU_LOG']);
        $this->assertSame('hatsu.local', $values['HATSU_DOMAIN']);
        $this->assertSame('a b', $values['SINGLE']);
    }

    public function test_merge_does_not_corrupt_spaced_example_lines(): void
    {
        $merged = EnvFile::merge(
            "HATSU_LOG = \"info,tokio::net=debug,sqlx::query=warn\"\nHATSU_DOMAIN = \"hatsu.local\"\n",
            ['HATSU_PRIMARY_ACCOUNT' => 'blog.rust-lang.org']
        );

        $this->assertSame(
            "HATSU_LOG=info,tokio::net=debug,sqlx::query=warn\nHATSU_DOMAIN=hatsu.local\nHATSU_PRIMARY_ACCOUNT=blog.rust-lang.org\n",
            $merged
        );
    }

    public function test_serialise_round_trips_and_quotes_only_when_needed(): void
    {
        $source = "# header\n\nPLAIN=simple\nSPACED=\"two words\"\n";

        $this->assertSame($source, EnvFile::serialise(EnvFile::parse($source)));
    }

    public function test_serialise_escapes_newlines_and_skips_keyless_rows(): void
    {
        $out = EnvFile::serialise([
            ['type' => 'variable', 'key' => 'MULTI', 'value' => "a\nb"],
            ['type' => 'variable', 'key' => '', 'value' => 'dropped'],
            ['type' => 'variable', 'key' => 'HASH', 'value' => 'a#b'],
        ]);

        $this->assertSame("MULTI=\"a\\nb\"\nHASH=\"a#b\"\n", $out);
    }

    public function test_merge_overrides_in_place_and_appends_new_keys(): void
    {
        $base = "# app\nAPP_ENV=local\n\nDB_HOST=127.0.0.1\n";

        $merged = EnvFile::merge($base, ['APP_ENV' => 'production', 'NEW_KEY' => 'v']);

        $this->assertSame(
            "# app\nAPP_ENV=production\n\nDB_HOST=127.0.0.1\nNEW_KEY=v\n",
            $merged,
            'existing keys keep their position; new keys are appended'
        );
    }

    public function test_merge_without_overrides_only_normalises_the_trailing_newline(): void
    {
        $this->assertSame("A=1\n", EnvFile::merge("A=1", []));
        $this->assertSame("A=1\n", EnvFile::merge("A=1\n", []));
        $this->assertSame('', EnvFile::merge('', []));
    }

    public function test_merge_onto_empty_base_writes_just_the_overrides(): void
    {
        $this->assertSame("A=1\nB=2\n", EnvFile::merge('', ['A' => '1', 'B' => '2']));
    }

    public function test_merge_ignores_non_string_override_values(): void
    {
        /** @phpstan-ignore-next-line deliberately passing a bad value */
        $merged = EnvFile::merge("A=1\n", ['A' => 2, 'B' => 'ok']);

        $this->assertSame("A=1\nB=ok\n", $merged);
    }

    public function test_merge_quotes_values_that_need_it(): void
    {
        $this->assertSame(
            "SECRET=\"a b#c\"\n",
            EnvFile::merge("SECRET=old\n", ['SECRET' => 'a b#c'])
        );
    }

    public function test_merge_keeps_a_dollar_in_a_given_value_away_from_compose_interpolation(): void
    {
        $hash = 'admin:$2y$10$NBMM7ztxyz';

        $this->assertSame(
            "TINYAUTH_AUTH_USERS='admin:\$2y\$10\$NBMM7ztxyz'\n",
            EnvFile::merge('', ['TINYAUTH_AUTH_USERS' => $hash])
        );
        $this->assertSame(
            "PASS=\"it's \$\$HOME\"\n",
            EnvFile::merge("PASS=old\n", ['PASS' => "it's \$HOME"]),
            'a value with a quote as well falls back to compose\'s $$ escape'
        );
        $this->assertSame($hash, EnvFile::parse(EnvFile::merge('', ['A' => $hash]))[0]['value']);
    }

    public function test_merge_leaves_the_repository_interpolation_alone(): void
    {
        $merged = EnvFile::merge(
            "DATABASE_URL=postgres://\${DB_USER}@db/app\nSINGLE='lit\$eral'\n",
            ['DB_USER' => 'app']
        );

        $this->assertSame(
            "DATABASE_URL=postgres://\${DB_USER}@db/app\nSINGLE='lit\$eral'\nDB_USER=app\n",
            $merged
        );
    }

    public function test_shell_lines_compose_refuses_are_commented_out(): void
    {
        // saltcorn's .env.example, lines 20-22 and 26.
        $example = "# stale values\n"
            . "unset DATABASE_URL SQLITE_FILEPATH SALTCORN_DB_DRIVER SALTCORN_DEFAULT_SCHEMA\n"
            . "unset PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE\n"
            . "\n"
            . "export SALTCORN_SESSION_SECRET='hrh64b45b3'\n"
            . "A+B=1\n";

        [$out, $lines] = EnvFile::withoutComposeRejectedLines($example);

        $this->assertSame([2, 3, 6], $lines);
        $this->assertSame(
            "# stale values\n"
            . "# unset DATABASE_URL SQLITE_FILEPATH SALTCORN_DB_DRIVER SALTCORN_DEFAULT_SCHEMA\n"
            . "# unset PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE\n"
            . "\n"
            . "export SALTCORN_SESSION_SECRET='hrh64b45b3'\n"
            . "# A+B=1\n",
            $out
        );
    }

    public function test_lines_compose_accepts_are_left_byte_for_byte(): void
    {
        // Every one of these was checked against `docker compose config`.
        $valid = "foo.bar=1\nKEY: val\nBARE\nexport A=1\n  indented=1\nKEY = v\n1ABC=2\nA-B=1\nA[0]=1\n"
            . "URL=http://x:1/y\nCERT=\"-----BEGIN\nnot a key line\n-----END\"\nAFTER=1\r\n";

        $this->assertSame([$valid, []], EnvFile::withoutComposeRejectedLines($valid));
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
