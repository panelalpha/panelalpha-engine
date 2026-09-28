<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Puts a project's memory limit on its account container: in the account's
 * compose file, so a recreated container keeps it, and on the running
 * container through `docker update`, so nothing restarts.
 *
 * Only the two memory keys change. Re-rendering the file from the template
 * instead would move an older account onto the current template's image and
 * mounts as a side effect of a limit change.
 */
final class AccountMemory
{
    private const SERVICE = 'dind';

    public function __construct(private readonly Dind $project)
    {
    }

    public function apply(): void
    {
        $mb = $this->project->userModel()->effectiveMemoryLimit();
        $container = $this->writeCompose($mb);

        if ($this->project->isRunning()) {
            // Swap equal to memory, as the template sets it: no swap at all.
            $this->project->system()->exec([
                'sudo', 'docker', 'update', '--memory', $mb . 'm', '--memory-swap', $mb . 'm', $container,
            ]);
        }
    }

    /** @return string the account's container name */
    private function writeCompose(int $mb): string
    {
        $filesystem = $this->project->system()->filesystem();
        $path = $this->project->composeFilePath();

        [$compose, $container] = self::withLimit($filesystem->fileGetContents($path), $mb);
        $filesystem->filePutContents($path, $compose);

        return $container ?? $this->project->userModel()->username;
    }

    /**
     * The compose file with the account capped at $mb, and its container name.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function withLimit(string $composeYaml, int $mb): array
    {
        $compose = Yaml::parse($composeYaml);
        if (!is_array($compose) || !is_array($compose['services'][self::SERVICE] ?? null)) {
            throw new RuntimeException("No '" . self::SERVICE . "' service in the account's compose file.");
        }
        $compose['services'][self::SERVICE]['mem_limit'] = $mb . 'M';
        $compose['services'][self::SERVICE]['memswap_limit'] = $mb . 'M';

        $name = $compose['services'][self::SERVICE]['container_name'] ?? null;

        return [Yaml::dump($compose, 8, 2), is_string($name) && $name !== '' ? $name : null];
    }
}
