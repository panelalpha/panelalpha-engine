<?php

namespace Tests\Integration\System\Firewall;

use App\System\Firewall\FirewallRule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\TestNetwork;

/** The host side: scripts/firewall.sh and its ufw provider, and who runs them. */
class FirewallScriptTest extends TestCase
{
    /** tests/scripts/firewall/ufw.test.sh runs ufw.sh against fake ufw, iptables, apt and systemctl. */
    public function test_the_ufw_provider_against_fakes(): void
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '') {
            $this->markTestSkipped('bash is not installed');
        }

        exec(escapeshellarg($bash) . ' ' . escapeshellarg(dirname(__DIR__, 5) . '/tests/scripts/firewall/ufw.test.sh') . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertContains('All passed.', $out);
    }

    /** A comment the CSF migration writes is one the API would take for that rule as well. */
    public function test_the_csf_migration_writes_comments_the_api_takes(): void
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '') {
            $this->markTestSkipped('bash is not installed');
        }
        $script = dirname(__DIR__, 5) . '/scripts/firewall/ufw.sh';
        // The first line is the comment as written; the reasons follow.
        $safe = static fn (string $comment, string $kind): string => explode("\n", (new Process(
            [$bash, '-c', 'source "$1"; safe_comment "$2" "$3"', '_', $script, $comment, $kind]
        ))->mustRun()->getOutput(), 2)[0];
        $allow = FirewallRule::fromArray(['action' => 'allow', 'protocol' => 'tcp', 'port' => '22', 'source' => TestNetwork::public3(1)]);
        $rules = [
            'host' => [$allow, FirewallRule::fromArray(['action' => 'deny', 'direction' => 'out', 'destination' => TestNetwork::public3(1)])],
            'route' => [$allow->with(['scope' => 'published']), FirewallRule::fromArray(['action' => 'deny', 'source' => TestNetwork::public3(1)])],
        ];

        $comments = ["Bob's office", "it's 'x'", "a\tin\tb", "x\ry", "a\x1b[31mb\x7f", "a\u{85}b", "\x01", 'in', 'out', 'log', 'log-all',
            'delete', 'in from office', 'block in office hours', 'out of office', 'keep out please', 'x in on y out on z', 'in in out x',
            '--rootdir=x', '--datadir=/tmp y', '--rootdir=a=b', 'panelalpha: mine', 'by Fail2Ban after 5 attempts', "by Fail2Ban's in x",
            'let them in', 'x app in y', 'zażółć 日本', 'IN', 'say "hi"',
            // ufw drops bytes that are not UTF-8 before it stores a comment.
            "\xffby Fail2Ban after 5", "\xffpanelalpha: mine", "i\xffn", "\xc0\x80--rootdir=x", "\xf4\x90\x80\x80log", "block i\xe2\x80n office", "Bob\x92s"];
        foreach ($comments as $comment) {
            foreach ($rules as $kind => $forKind) {
                $written = $safe($comment, $kind);
                $this->assertTrue(mb_check_encoding($written, 'UTF-8'), bin2hex($comment));
                $this->assertDoesNotMatchRegularExpression(FirewallRule::COMMENT_REFUSED_CHARACTERS, $written, $comment);
                $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f\x7f]|\xc2[\x80-\x9f]/', $written, $comment);
                $this->assertStringStartsNotWith(FirewallRule::MANAGED_PREFIX, $written, $comment);
                $this->assertStringStartsNotWith('by Fail2Ban', $written, $comment);
                foreach ($forKind as $rule) {
                    $this->assertSame([], $rule->with(['comment' => $written])->problems(), "{$kind}: {$comment} -> {$written}");
                }
            }
        }
        // A comment ufw stores is written as it is.
        foreach (['let them in', 'x in on y', 'zażółć 日本', 'IN', 'say "hi"', 'see --rootdir=x'] as $comment) {
            $this->assertSame($comment, $safe($comment, 'route'));
        }
    }
}
