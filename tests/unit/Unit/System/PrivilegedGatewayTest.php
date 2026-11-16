<?php

namespace Tests\Unit\System;

use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use App\System\Privileged\Root;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PrivilegedGatewayTest extends TestCase
{
    private const SUDO = '~(?<![\w-])sudo(?![\w-])~i';

    /** nsenter at the start of a literal or after a shell operator, never inside a message. */
    private const NSENTER = '~(?:^[\'"]?|[|&;(!]|\b(?:then|else|do|elif))\s*nsenter(?![\w-])~';

    private const CLIENT = '~(?<![\w-])pa-root(?![\w-])~';

    private const GATEWAY_CLASSES = 'core/app/System/Privileged/';

    private const ROOT = 'core/app/System/Privileged/Root.php';

    public function test_nothing_in_the_engine_says_sudo(): void
    {
        $found = [];
        foreach ($this->appFiles() as $file) {
            foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $index => $line) {
                if (preg_match(self::SUDO, $line) === 1) {
                    $found[] = $this->relative($file).':'.($index + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame([], $found, 'Core gains root through the root broker alone');
    }

    public function test_only_root_names_the_brokers_client(): void
    {
        $this->assertSame([], $this->literalsMatching(self::CLIENT, [self::ROOT]), 'Ask App\System\Privileged for a named command');
    }

    public function test_no_command_enters_the_hosts_namespaces_itself(): void
    {
        $this->assertSame([], $this->literalsMatching(self::NSENTER, []), 'Ask for a host action: the broker enters them');
    }

    public function test_only_the_gateway_classes_turn_a_command_into_root(): void
    {
        $users = array_values(array_filter(
            array_map(fn (string $file): string => $this->relative($file), $this->appFiles()),
            fn (string $file): bool => ! str_starts_with($file, self::GATEWAY_CLASSES)
                && preg_match('~\\\\Privileged\\\\Root\b~', (string) file_get_contents($this->root().'/'.$file)) === 1
        ));

        $this->assertSame([], $users, 'Ask App\System\Privileged for a named command instead');
    }

    public function test_a_command_that_is_not_the_gateways_never_reaches_the_host(): void
    {
        $this->expectException(InvalidArgument::class);

        Privileged::onHost(['rm', '-rf', '/']);
    }

    public function test_a_request_names_the_client_its_action_and_its_values(): void
    {
        $this->assertSame(['/usr/local/lib/panelalpha/pa-root', 'files.removeTree', '--', '/x'], Privileged::files()->removeTree('/x'));
        $this->assertSame(
            ['/usr/local/lib/panelalpha/pa-root', '--host', 'files.removeTree', '--', '/x'],
            Privileged::onHost(Privileged::files()->removeTree('/x')),
        );
        $this->assertSame("/usr/local/lib/panelalpha/pa-root lines.inspectImage -- 'a b'", Root::actionLine('lines', 'inspectImage', ['a b']));
    }

    /** The broker failing to reach the host is not the host answering. */
    public function test_a_host_the_broker_cannot_enter_is_told_from_the_hosts_answer(): void
    {
        $this->assertTrue(Privileged::hostNamespacesUnavailable("pa-root: cannot enter the host's namespaces: Operation not permitted"));
        $this->assertTrue(Privileged::hostNamespacesUnavailable("pa-root: cannot enter the host's namespaces: Permission denied"));
        $this->assertFalse(Privileged::hostNamespacesUnavailable('pa-root: Operation not permitted'));
        $this->assertFalse(Privileged::hostNamespacesUnavailable("id: 'alice': no such user"));
    }

    public function test_the_checks_tell_a_command_from_a_message_that_names_it(): void
    {
        foreach (["'sudo'", 'run with SUDO', '(is sudo working?)'] as $text) {
            $this->assertMatchesRegularExpression(self::SUDO, $text);
        }
        foreach (['pseudo', 'sudoers-like', 'rootfile://'] as $text) {
            $this->assertDoesNotMatchRegularExpression(self::SUDO, $text);
        }
        foreach ([' then nsenter -t 1', "'nsenter'"] as $command) {
            $this->assertMatchesRegularExpression(self::NSENTER, $command);
        }
        $this->assertDoesNotMatchRegularExpression(self::NSENTER, "'Running host recovery via nsenter (pid 1)'");
        $this->assertMatchesRegularExpression(self::CLIENT, "'/usr/local/lib/panelalpha/pa-root'");
        $this->assertDoesNotMatchRegularExpression(self::CLIENT, "'pa-root-audit'");
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string> file:line: literal
     */
    private function literalsMatching(string $pattern, array $allowed): array
    {
        $found = [];
        foreach ($this->appFiles() as $file) {
            $relative = $this->relative($file);
            if (in_array($relative, $allowed, true)) {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token)
                    && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match($pattern, $token[1]) === 1) {
                    $found[] = $relative.':'.$token[2].': '.trim($token[1]);
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function appFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root().'/core/app', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (str_ends_with($file->getPathname(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return substr($path, strlen($this->root()) + 1);
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
