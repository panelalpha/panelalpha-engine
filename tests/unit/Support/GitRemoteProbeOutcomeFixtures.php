<?php

namespace Tests\Support;

use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Http\OutboundUrl;

trait GitRemoteProbeOutcomeFixtures
{
    private const URL = 'https://example.test/o/r.git';

    protected function setUp(): void
    {
        parent::setUp();
        OutboundUrl::setResolverForTests(static fn (string $host): array => [TestNetwork::routable(4)]);
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        parent::tearDown();
    }

    /**
     * @param array{timedOut: bool, ok: bool, stderr: string}|\Throwable $answer
     */
    private function probe(array|\Throwable $answer, int $attempts = 2): object
    {
        return new class ($answer, $attempts) extends GitRemoteProbe {
            /** @var list<list<string>> */
            public array $commands = [];

            /** @var list<?string> */
            public array $askPassSeen = [];

            public function __construct(private array|\Throwable $answer, int $attempts)
            {
                parent::__construct(7, $attempts);
            }

            protected function run(array $command): array
            {
                $this->commands[] = $command;
                $askPass = null;
                foreach ($command as $arg) {
                    if (str_starts_with($arg, 'GIT_ASKPASS=')) {
                        $askPass = substr($arg, strlen('GIT_ASKPASS='));
                    }
                }
                $this->askPassSeen[] = $askPass !== null && is_file($askPass) ? $askPass : null;
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };
    }
}
