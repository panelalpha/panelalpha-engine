<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\TelemetryFields;
use Tests\TestCase;

/**
 * The per-account facts a telemetry event carries.
 *
 * Every one of them is read through the same swallow-and-log wrapper, and
 * that is the design rather than defensiveness: these run alongside a deploy,
 * against an account that may have been deleted between the event being
 * queued and the field being read. A telemetry lookup that throws would fail
 * the deploy it was only meant to describe.
 */
class TelemetryFieldsTest extends TestCase
{
    public function test_a_value_that_can_be_read_is_returned(): void
    {
        $this->assertSame('answer', TelemetryFields::safely(static fn (): string => 'answer', 'fallback'));
    }

    public function test_a_value_that_throws_falls_back_instead(): void
    {
        $this->assertSame(
            'fallback',
            TelemetryFields::safely(static fn (): string => throw new \RuntimeException('gone'), 'fallback')
        );
    }

    public function test_even_an_error_is_absorbed(): void
    {
        // A missing table is a TypeError or an Error, not an Exception, and
        // catching only Exception would still take the deploy down.
        $this->assertNull(TelemetryFields::safely(static fn () => throw new \Error('no such method'), null));
    }

    public function test_the_fallback_keeps_the_shape_the_caller_expects(): void
    {
        $this->assertSame([], TelemetryFields::safely(static fn (): array => throw new \RuntimeException(''), []));
        $this->assertFalse(TelemetryFields::safely(static fn (): bool => throw new \RuntimeException(''), false));
    }

    public function test_an_account_that_does_not_exist_reports_nothing_about_itself(): void
    {
        // The account was deleted between the event being queued and this
        // running, or the database is unreachable. Either way, no exception.
        $fields = new TelemetryFields('nobody-' . bin2hex(random_bytes(4)));

        $this->assertNull($fields->user());
        $this->assertNull($fields->repoUrl());
        $this->assertFalse($fields->repoIsPrivate());
        $this->assertSame([], $fields->accountDetails());
        $this->assertFalse($fields->isDindAccount());
    }

    public function test_a_repository_with_no_token_is_not_private(): void
    {
        // repoIsPrivate() is derived rather than stored: a token is what
        // makes a clone private, so no repo means no answer to give.
        $fields = new TelemetryFields('nobody-' . bin2hex(random_bytes(4)));

        $this->assertFalse($fields->repoIsPrivate());
    }

    /**
     * The disclosure this rule exists to stop.
     *
     * Private used to mean "a token is stored on the account", so an `ssh://`
     * or a `git@host:owner/repo` remote -- which nobody without a key can
     * clone, and which no token is ever stored for -- counted as public and
     * had its path sent in the clear.
     */
    public function test_a_repository_a_stranger_could_not_clone_is_private(): void
    {
        foreach ([
            'git@github.com:acme/internal.git',
            'ssh://git@gitlab.com/acme/internal.git',
            'https://acme:ghp_token@github.com/acme/internal.git',
            'git://old.example.com/acme/internal.git',
            'http://gitea.acme.internal/acme/internal.git',
        ] as $url) {
            $this->assertTrue(TelemetryFields::isPrivateRepo($url, null), "reported public: {$url}");
        }
    }

    public function test_a_plain_https_repository_with_no_token_is_public(): void
    {
        $this->assertFalse(
            TelemetryFields::isPrivateRepo('https://github.com/vercel/next.js.git', null)
        );
    }

    public function test_a_stored_token_makes_any_repository_private(): void
    {
        $this->assertTrue(
            TelemetryFields::isPrivateRepo('https://github.com/acme/paid-theme.git', 'ghp_token')
        );
        // Whitespace is not a credential.
        $this->assertFalse(
            TelemetryFields::isPrivateRepo('https://github.com/acme/paid-theme.git', '  ')
        );
    }

    public function test_no_repository_is_neither_public_nor_private(): void
    {
        $this->assertFalse(TelemetryFields::isPrivateRepo(null, 'ghp_token'));
        $this->assertFalse(TelemetryFields::isPrivateRepo('', null));
    }

    /**
     * An account with no home directory must not pay for a git process, and
     * must not report a repository it could not see.
     */
    public function test_an_account_with_no_project_directory_has_no_checkout(): void
    {
        $fields = new TelemetryFields('nobody-' . bin2hex(random_bytes(4)));

        $this->assertSame(['present' => false], $fields->checkout());
        $this->assertNull($fields->repoUrl());
        $this->assertNull($fields->repoSource());
    }

    public function test_the_project_directory_is_derived_from_the_username(): void
    {
        $fields = new TelemetryFields('acme');

        $this->assertStringEndsWith('/acme/project', $fields->projectDir());
    }

    public function test_a_project_directory_that_is_not_there_lists_no_manifests(): void
    {
        $fields = new TelemetryFields('nobody-' . bin2hex(random_bytes(4)));

        $this->assertSame([], $fields->manifests());
    }

    public function test_the_username_is_carried_as_given(): void
    {
        $this->assertSame('acme', (new TelemetryFields('acme'))->username);
    }

    /**
     * An account that no longer exists has no names to give, and asking must
     * cost nothing and throw nothing — the same rule every other field here
     * follows. This runs moments after a failed deploy, which is exactly when
     * the account is about to be deleted.
     */
    public function test_an_account_that_does_not_exist_names_no_domains(): void
    {
        $this->assertSame([], (new TelemetryFields('nobody-' . bin2hex(random_bytes(4))))->domains());
    }

    /**
     * The one field a report carries from a `domains` row somebody typed into.
     * A URL with credentials in it must not be relayed as though it were a
     * hostname, and the answer is to drop the entry rather than to mask it:
     * a half-redacted name is not an address anybody can open.
     */
    public function test_a_hostname_is_reduced_to_a_bare_name_or_refused(): void
    {
        $this->assertSame('shop.acme.com', TelemetryFields::normalizeHostname('  Shop.ACME.com.  '));
        $this->assertSame('shop.acme.com', TelemetryFields::normalizeHostname('https://shop.acme.com/path'));
        $this->assertSame('www.shop.acme.com', TelemetryFields::normalizeHostname('www.shop.acme.com'));

        foreach ([
            '',
            '   ',
            'https://acme:ghp_token@shop.acme.com/',
            'shop acme com',
            'localhost',
            'shop.acme.com:8443',
            'not a name at all',
        ] as $refused) {
            $this->assertSame('', TelemetryFields::normalizeHostname($refused), "accepted: {$refused}");
        }
    }
}
