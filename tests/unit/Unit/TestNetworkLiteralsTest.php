<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\TestNetwork;

/**
 * Tests take every IP address from the test network config, so no real host's
 * address is written into one: PHPUnit through {@see TestNetwork}, the API suite
 * through tests/api/helpers/test-network.ts and the shell tests through
 * tests/scripts/test-network.sh. Recorded command output under fixtures/ is data, and
 * a line naming a version holds version numbers, not addresses.
 */
class TestNetworkLiteralsTest extends TestCase
{
    /** Also right after an escape such as `\n`, before a full stop and with regex-escaped dots; not inside a longer dotted number, a name like `net45.1.1.11` or a `1.2.3.4-rc` version. */
    private const IPV4 = '~(?:(?<![\w.-])|(?<=\\[nrtv0])|(?<=\\x[0-9a-fA-F]{2}))(\d{1,3}(?:\\\\?\.\d{1,3}){3})(?!\.?\d)(?![-\w])~';

    public function test_no_test_writes_an_ip_address(): void
    {
        $this->assertSame([], $this->literals(), 'Use Tests\Support\TestNetwork instead of these addresses');
    }

    public function test_every_configured_network_is_defined(): void
    {
        foreach (['PUBLIC', 'PUBLIC_2', 'PUBLIC_3', 'ROUTABLE', 'PRIVATE', 'PRIVATE_2', 'CONTAINER', 'CONTAINER_2'] as $name) {
            $this->assertMatchesRegularExpression('~\A[\d.]+/\d+\z~', TestNetwork::net($name));
        }
    }

    /** @return list<string> file:line: address */
    private function literals(): array
    {
        $found = [];
        foreach ($this->testFiles() as $file) {
            foreach (file($file) ?: [] as $number => $line) {
                if (stripos($line, 'version') !== false) {
                    continue;
                }
                foreach ($this->addressesIn($line) as $address) {
                    $found[] = $this->relative($file).':'.($number + 1).': '.$address;
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function addressesIn(string $line): array
    {
        preg_match_all(self::IPV4, $line, $matches);

        return array_values(array_filter(
            array_map(static fn (string $match): string => str_replace('\\', '', $match), $matches[1]),
            static fn (string $candidate): bool => filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        ));
    }

    /** @return list<string> */
    private function testFiles(): array
    {
        $files = array_merge(
            $this->filesUnder('tests/unit', '~\.php\z~', ['tests/unit/Support/TestNetwork.php']),
            $this->filesUnder('tests/api', '~\.(ts|js|mjs)\z~', ['tests/api/helpers/test-network.ts']),
            $this->filesUnder('tests/scripts', '~\.sh\z~', ['tests/scripts/test-network.sh']),
        );
        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $allowed  the helpers that define the addresses
     * @return list<string>
     */
    private function filesUnder(string $directory, string $pattern, array $allowed): array
    {
        $root = $this->root();
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relative = $this->relative($path);
            if (preg_match($pattern, $path) === 1 && ! $this->isExcluded($relative) && ! in_array($relative, $allowed, true)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function isExcluded(string $relative): bool
    {
        return preg_match('~(^|/)(fixtures|node_modules|\.playwright|playwright-report|test-results)/~', $relative) === 1;
    }

    private function relative(string $path): string
    {
        return substr($path, strlen($this->root()) + 1);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
