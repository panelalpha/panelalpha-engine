<?php

namespace Tests\Support;

trait CheckoutFactsFixtures
{
    /**
     * A runner that answers from a table of git subcommands.
     *
     * @param array<string, ?string> $answers keyed by the arguments after `-C <dir>`
     * @return callable(list<string>): ?string
     */
    private function runner(array $answers, ?array &$seen = null): callable
    {
        $seen = [];

        return static function (array $argv) use ($answers, &$seen): ?string {
            $index = array_search('-C', $argv, true);
            $key = implode(' ', array_slice($argv, (int) $index + 2));
            $seen[] = $key;

            return $answers[$key] ?? null;
        };
    }

    /**
     * @return array<string, ?string>
     */
    private function repository(array $overrides = []): array
    {
        return array_replace([
            'rev-parse --is-inside-work-tree' => "true\n",
            'config --get remote.origin.url' => "https://github.com/vercel/next.js.git\n",
            'log -1 --format=%H %ct' => "0d2f3a4b5c6d7e8f90a1b2c3d4e5f60718293a4b 1756000000\n",
            'rev-parse --abbrev-ref HEAD' => "main\n",
            'rev-parse --is-shallow-repository' => "true\n",
        ], $overrides);
    }

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->cleanup = [];
        parent::tearDown();
    }
}
