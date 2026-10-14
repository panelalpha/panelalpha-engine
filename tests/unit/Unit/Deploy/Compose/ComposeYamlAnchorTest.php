<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeYaml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Compose files Docker reads and Symfony does not.
 *
 * Lychee declares an anchor on a line of its own. Docker accepts the file —
 * it is the project's own, and it works — while Symfony's parser rejects it
 * with "Mapping values are not allowed in multi-line blocks". That exception
 * came back from `project_create` verbatim, and the rollback deleted the
 * deploy log before anyone could see why.
 *
 * The rescue is a fallback, never a rewrite of a file that already parses.
 */
class ComposeYamlAnchorTest extends TestCase
{
    /** Lychee's shape: anchor alone, behind comments, under its key. */
    private const LYCHEE = <<<'YAML'
        x-base-lychee-setup:
          # Shared by the api and the worker.
          # Two comment lines, as upstream has.
          &base-lychee-setup
          image: ghcr.io/lycheeorg/lychee:latest
          restart: unless-stopped

        services:
          lychee_api:
            <<: *base-lychee-setup
            ports:
              - "8000:80"
          lychee_db:
            image: mariadb:11
        YAML;

    public function test_symfony_alone_cannot_read_it(): void
    {
        // The premise. If Symfony ever learns this shape, the fallback below
        // becomes dead code and this test says so.
        $this->expectException(\Symfony\Component\Yaml\Exception\ParseException::class);
        Yaml::parse(self::LYCHEE);
    }

    public function test_it_is_read_and_the_merge_key_resolves(): void
    {
        $parsed = ComposeYaml::parse(self::LYCHEE);

        $this->assertNotNull($parsed, 'a file Docker accepts must not be refused');
        $this->assertSame(['lychee_api', 'lychee_db'], array_keys($parsed['services']));
        // The anchor is not merely tolerated — it still means what it meant.
        $this->assertSame(
            'ghcr.io/lycheeorg/lychee:latest',
            $parsed['services']['lychee_api']['image']
        );
        $this->assertSame('unless-stopped', $parsed['services']['lychee_api']['restart']);
    }

    /** A file Symfony already reads is passed through untouched. */
    public function test_an_ordinary_file_is_not_rewritten(): void
    {
        $yaml = "services:\n  app:\n    image: nginx\n    ports:\n      - \"80:80\"\n";

        $this->assertSame(Yaml::parse($yaml), ComposeYaml::parse($yaml));
    }

    /** The conventional anchor position keeps working. */
    public function test_an_anchor_already_on_its_key_still_works(): void
    {
        $yaml = "x-env: &env\n  TZ: UTC\nservices:\n  app:\n    environment:\n      <<: *env\n";

        $parsed = ComposeYaml::parse($yaml);

        $this->assertSame(['TZ' => 'UTC'], $parsed['services']['app']['environment']);
    }

    /** Genuinely broken YAML answers null rather than throwing. */
    public function test_unreadable_yaml_returns_null(): void
    {
        $this->assertNull(ComposeYaml::parse("services:\n  app:\n   image: [unclosed\n"));
    }

    /**
     * An anchor whose line above is not a key is left alone: moving it there
     * would be a guess, and guessing at someone's compose file is worse than
     * declining to read it.
     */
    public function test_an_anchor_it_cannot_place_is_not_moved(): void
    {
        $yaml = "services:\n  app:\n    image: nginx\n  &loose\n";

        // Whatever the outcome, it must not invent a parse.
        $parsed = ComposeYaml::parse($yaml);
        if ($parsed !== null) {
            $this->assertArrayHasKey('services', $parsed);
        } else {
            $this->assertNull($parsed);
        }
    }

    /**
     * Baserow writes the anchor with a comment after it. The rescue's regex
     * was anchored end-of-string, so the line did not match, the rescue never
     * fired, and `project_create` refused the repo with "The compose file in
     * this project could not be read as YAML" -- for a file declaring nine
     * services. An anchor with something said about it is still an anchor
     * alone on its line.
     */
    public function test_an_anchor_with_a_trailing_comment_is_still_rescued(): void
    {
        $yaml = "x-backend-variables:\n"
            . "  &backend-variables # Most users should only need these four.\n"
            . "  SECRET_KEY: secret\n"
            . "services:\n  backend:\n    image: baserow/backend:2.3.3\n";

        $parsed = ComposeYaml::parse($yaml);

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('services', $parsed);
        $this->assertSame('baserow/backend:2.3.3', $parsed['services']['backend']['image']);
    }

    /** The comment travels with the anchor rather than being dropped. */
    public function test_the_comment_does_not_break_the_key_it_moves_onto(): void
    {
        $yaml = "x-a:\n  &a # why\n  K: v\nservices:\n  app:\n    image: nginx\n";

        $parsed = ComposeYaml::parse($yaml);

        $this->assertIsArray($parsed);
        $this->assertSame('v', $parsed['x-a']['K']);
    }
}
