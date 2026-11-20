<?php

namespace Tests\Integration\System\Project\Dind\Strategy;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Sidecar\SidecarPasswords;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\RuntimeSidecars;
use App\System\Project\Dind\Strategy\AccountSecrets;
use App\System\Project\Dind\Strategy\EntrypointWriter;

/**
 * The real DeployStrategy with fixed secrets, harvested sidecars and, when asked, a recording entrypoint writer.
 */
final class HarnessDeployStrategy extends DeployStrategy
{
    private ?RuntimeSidecars $harnessSidecars = null;

    /**
     * @param  array{services: array<string, mixed>, volumes: array<string, mixed>, env: array<string, string>}  $harvested
     * @param  \Closure(list<mixed>): void  $record
     */
    public function __construct(
        private Dind $harnessDind,
        private array $harvested,
        private ?bool $entrypointAnswer,
        private \Closure $record
    ) {
        parent::__construct($harnessDind);
    }

    public function secrets(): AccountSecrets
    {
        return new class($this->harnessDind) extends AccountSecrets
        {
            public function userEnvVars(): array
            {
                return [];
            }

            public function instanceSecret(): string
            {
                return str_repeat('0', 64);
            }

            public function for(string $purpose): string
            {
                return 'stub-secret:'.$purpose;
            }

            public function generatedSecretKeyBase(): string
            {
                return 'stub-secret-key-base';
            }

            public function notYetAnnounced(array $names): array
            {
                return $names;
            }
        };
    }

    public function sidecars(): RuntimeSidecars
    {
        return $this->harnessSidecars ??= new class($this->harnessDind, $this->harvested) extends RuntimeSidecars
        {
            /** @param  array<string, mixed>  $harvested */
            public function __construct(Dind $dind, private array $harvested)
            {
                parent::__construct($dind);
            }

            public function runtimeSidecarsFromProject(string $projectDir): array
            {
                return $this->harvested;
            }

            public function passwords(): SidecarPasswords
            {
                return SidecarPasswords::forMode(SidecarPasswords::MODE_DERIVED, 'stub-seed');
            }
        };
    }

    public function entrypoint(): EntrypointWriter
    {
        if ($this->entrypointAnswer === null) {
            return parent::entrypoint();
        }

        return new class($this->harnessDind, $this->entrypointAnswer, $this->record) extends EntrypointWriter
        {
            public function __construct(Dind $dind, private bool $answer, private \Closure $record)
            {
                parent::__construct($dind);
            }

            public function write(
                array $decision,
                string $projectDir,
                ?string $chown,
                array $prepend = [],
                ?AppConfig $appConfig = null
            ): bool {
                ($this->record)(['entrypoint', $decision, $projectDir, $chown, $prepend, $appConfig !== null]);

                return $this->answer;
            }
        };
    }
}
