<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Port\ListeningSockets;
use PHPUnit\Framework\TestCase;

/**
 * The runtime probe republished a site onto SSH.
 *
 * Gitea's image runs sshd beside the app under s6. sshd binds 22 at once;
 * Gitea binds 3000 a moment later. The probe looked in that gap, saw only 22,
 * discarded the correctly detected 3000 and rewrote the mapping to `3000:22`
 * -- so `app_port`, `deploy_port`, `health_ports` and docker's own
 * `PublishedPort` all read 3000 while the traffic went to sshd. The previous
 * bug at least advertised what it was doing.
 *
 * Fixing the Dockerfile parser was not enough on its own: this is an
 * independent code path that undid it downstream.
 */
class RuntimePortChoiceTest extends TestCase
{
    /** Non-loopback IPv4, as /proc/net/tcp spells it. */
    private const ANY = '00000000';

    /**
     * @param list<int> $ports
     * @return list<array{addr: string, port: int}>
     */
    private function listening(array $ports): array
    {
        return array_map(fn (int $p): array => ['addr' => self::ANY, 'port' => $p], $ports);
    }

    /**
     * The moment that produced the bug: sshd up, Gitea not yet. Answering
     * null means "nothing worth pointing at yet", which is what lets the
     * caller keep waiting rather than commit to SSH.
     */
    public function test_ssh_alone_is_not_an_answer(): void
    {
        $this->assertNull(ListeningSockets::chooseAppPort($this->listening([22]), 3000));
    }

    /** A moment later, once the app is up, the app wins. */
    public function test_the_app_wins_once_it_is_listening(): void
    {
        $this->assertNull(
            ListeningSockets::chooseAppPort($this->listening([22, 3000]), 3000),
            'the expected port is being served, so nothing should be republished'
        );
    }

    /** And when the recipe guessed wrong, the real web port is chosen over SSH. */
    public function test_a_real_web_port_is_preferred_over_ssh(): void
    {
        $this->assertSame(3000, ListeningSockets::chooseAppPort($this->listening([22, 3000]), 8080));
    }

    /** Mail and DNS are not front doors either. */
    public function test_mail_and_dns_are_not_answers(): void
    {
        $this->assertNull(ListeningSockets::chooseAppPort($this->listening([25, 53, 993]), 8080));
    }

    /** The behaviour this was always for: an app that ignored $PORT. */
    public function test_it_still_finds_a_relocated_app(): void
    {
        $this->assertSame(8090, ListeningSockets::chooseAppPort($this->listening([8090]), 8080));
    }

    /** Loopback-only is still unreachable, fix or no fix. */
    public function test_loopback_is_still_ignored(): void
    {
        $this->assertNull(
            ListeningSockets::chooseAppPort([['addr' => '0100007F', 'port' => 3000]], 8080)
        );
    }
}
