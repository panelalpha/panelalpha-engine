<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\Redactor;
use PHPUnit\Framework\TestCase;

/**
 * The redactor is the only thing standing between a customer's build log and
 * our ingest endpoint, so these tests are written as "this must never appear",
 * not as "this should look nice".
 */
class RedactorTest extends TestCase
{
    public function test_masks_credentials_in_any_url_scheme(): void
    {
        $line = Redactor::line('could not connect to postgres://app:hunter2@db:5432/main');

        $this->assertStringNotContainsString('hunter2', $line);
        $this->assertStringContainsString('postgres://***@db', $line);
    }

    public function test_masks_vendor_token_shapes(): void
    {
        $tokens = [
            'ghp_abcdefghijklmnopqrstuvwxyz0123456789',
            'github_pat_11ABCDEFG0abcdefghijklmno',
            'glpat-abcdefghijklmnopqrst',
            'AKIAIOSFODNN7EXAMPLE',
            'xoxb-1234567890-abcdefghij',
            'sk_live_abcdefghijklmnopqrstuvwx',
        ];

        foreach ($tokens as $token) {
            $this->assertStringNotContainsString(
                $token,
                Redactor::line("fetching with {$token} failed"),
                "token {$token} survived redaction"
            );
        }
    }

    public function test_masks_a_jwt(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        $this->assertStringNotContainsString($jwt, Redactor::line("Bearer token {$jwt}"));
    }

    public function test_masks_secret_looking_assignments_whatever_the_prefix(): void
    {
        $cases = [
            'APP_KEY=base64:abcdefgh',
            'DB_PASSWORD: hunter2',
            'NEXT_PUBLIC_STRIPE_SECRET_KEY="sk_test_value"',
            '--auth-token=abcdef',
        ];

        foreach ($cases as $case) {
            $redacted = Redactor::line($case);
            $this->assertStringContainsString('***', $redacted, "no mask applied to: {$case}");
        }

        $this->assertStringNotContainsString('hunter2', Redactor::line('DB_PASSWORD: hunter2'));
    }

    public function test_masks_authorization_headers(): void
    {
        $this->assertSame(
            'Authorization: Bearer ***',
            Redactor::line('Authorization: Bearer abc.def.ghi')
        );
    }

    public function test_drops_a_private_key_block_entirely(): void
    {
        $this->assertSame('', Redactor::line('-----BEGIN RSA PRIVATE KEY-----'));
        $this->assertSame('', Redactor::line('-----BEGIN OPENSSH PRIVATE KEY-----'));
    }

    public function test_masks_emails(): void
    {
        $this->assertStringNotContainsString(
            'jane.doe@example.com',
            Redactor::line('git author jane.doe@example.com')
        );
    }

    /**
     * A public address identifies the box the report came from; a bind address
     * is the whole point of a port-detection line and has to survive.
     */
    public function test_masks_public_ips_but_keeps_bind_addresses(): void
    {
        $this->assertStringNotContainsString('203.0.113.7', Redactor::line('connect to 203.0.113.7 failed'));

        $this->assertStringContainsString('0.0.0.0:3000', Redactor::line('listening on 0.0.0.0:3000'));
        $this->assertStringContainsString('127.0.0.1', Redactor::line('proxy to 127.0.0.1:8080'));
        $this->assertStringContainsString('172.17.0.2', Redactor::line('container ip 172.17.0.2'));
    }

    public function test_does_not_mistake_a_version_string_for_an_ip(): void
    {
        $this->assertStringContainsString('1.24.13', Redactor::line('running go 1.24.13'));
    }

    public function test_replaces_the_account_name_and_its_home_directory(): void
    {
        $line = Redactor::line('/home/acme7x/project/src/index.ts: acme7x cannot write', 'acme7x');

        $this->assertStringNotContainsString('acme7x', $line);
        $this->assertStringContainsString('<account>', $line);
    }

    public function test_replaces_any_other_home_directory_too(): void
    {
        $this->assertStringNotContainsString(
            'someoneelse',
            Redactor::line('cp: cannot stat /home/someoneelse/project/x')
        );
    }

    public function test_truncates_an_overlong_line(): void
    {
        $line = Redactor::line(str_repeat('a', Redactor::MAX_LINE_BYTES + 500));

        $this->assertLessThanOrEqual(Redactor::MAX_LINE_BYTES + 5, strlen($line));
    }

    public function test_tail_keeps_the_end_and_respects_both_caps(): void
    {
        $lines = [];
        for ($i = 0; $i < Redactor::MAX_LINES + 40; $i++) {
            $lines[] = "line {$i}";
        }

        $tail = Redactor::tail($lines);

        $this->assertLessThanOrEqual(Redactor::MAX_LINES, count($tail));
        $this->assertSame('line ' . (count($lines) - 1), end($tail));
    }

    public function test_tail_stays_under_the_byte_cap(): void
    {
        $lines = array_fill(0, Redactor::MAX_LINES, str_repeat('x', Redactor::MAX_LINE_BYTES));

        $tail = Redactor::tail($lines);
        $bytes = array_sum(array_map(static fn (string $l): int => strlen($l) + 1, $tail));

        $this->assertLessThanOrEqual(Redactor::MAX_TAIL_BYTES, $bytes);
    }

    public function test_tail_drops_blank_and_fully_redacted_lines(): void
    {
        $tail = Redactor::tail(['', '   ', '-----BEGIN CERTIFICATE-----', 'real line']);

        $this->assertSame(['real line'], $tail);
    }

    public function test_strips_ansi_escapes(): void
    {
        $this->assertSame('build failed', Redactor::line("\x1B[31mbuild failed\x1B[0m"));
    }

    public function test_a_structured_report_is_scrubbed_by_shape_not_by_an_allow_list(): void
    {
        // Hand-listing the safe keys of a report that grows every release is
        // how a leak eventually ships: somebody adds a field, nobody adds it
        // to the allow-list, and the redactor keeps passing it through because
        // it never knew about it. So every string is scrubbed wherever it is.
        $scrubbed = Redactor::tree([
            'services' => [
                ['image' => 'acme/api', 'env' => ['GITHUB_TOKEN=ghp_abcdefghijklmnopqrstuvwxyz01']],
            ],
        ]);

        $this->assertStringNotContainsString(
            'ghp_abcdefghijklmnopqrstuvwxyz01',
            (string) json_encode($scrubbed)
        );
    }

    public function test_a_value_under_a_key_that_names_a_secret_goes_whatever_it_looks_like(): void
    {
        // "changeme" is not a token shape and never will be. The key is what
        // condemns it.
        $scrubbed = Redactor::tree(['defaults' => ['DB_PASSWORD' => 'changeme', 'NODE_ENV' => 'production']]);

        $this->assertSame(Redactor::MASK, $scrubbed['defaults']['DB_PASSWORD']);
        $this->assertSame('production', $scrubbed['defaults']['NODE_ENV']);
    }

    public function test_a_list_of_secrets_keeps_its_shape_and_loses_every_element(): void
    {
        $scrubbed = Redactor::tree(['api_keys' => ['one', 'two']]);

        $this->assertSame([Redactor::MASK, Redactor::MASK], $scrubbed['api_keys']);
    }

    public function test_numbers_and_booleans_are_left_alone(): void
    {
        // A port, a status code and a duration are the diagnostic, and there
        // is nothing in an integer to leak.
        $scrubbed = Redactor::tree(['port' => 8080, 'http_code' => 502, 'time' => 0.31, 'healthy' => false]);

        $this->assertSame(['port' => 8080, 'http_code' => 502, 'time' => 0.31, 'healthy' => false], $scrubbed);
    }

    public function test_lists_and_depth_are_capped(): void
    {
        $wide = Redactor::tree(range(1, Redactor::MAX_TREE_ITEMS + 50));
        $this->assertCount(Redactor::MAX_TREE_ITEMS + 1, $wide);
        $this->assertSame('truncated', $wide['…']);

        $deep = ['a' => ['b' => ['c' => ['d' => 'bottom']]]];
        $this->assertSame('…', Redactor::tree($deep, null, 100, 2)['a']['b']);
    }

    public function test_prose_keeps_a_long_paragraph_and_the_breaks_around_it(): void
    {
        $paragraph = str_repeat('the deploy finishes and the site does not answer. ', 40);

        $prose = Redactor::prose("What happened.\n\n" . $paragraph);

        // Build output is a stream of short lines and capping each is right;
        // a paragraph somebody typed is one line, and the log budget would
        // amputate it.
        $this->assertGreaterThan(Redactor::MAX_LINE_BYTES, strlen($prose));
        $this->assertStringContainsString("\n\n", $prose);
    }
}
