<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\EnvFile;
use App\Lib\Project\ApiAccess;
use App\System\Project\Dind as DindProject;

/**
 * The Engine API address, token and certificate a staging, clone or push
 * brought over from another project, in the run file and in
 * `.env.panelalpha` beside it.
 *
 * They are the other project's, so the copy would hold that project's token.
 * Both files get the values this project's own allowance gives it instead,
 * which are none for a project that is not allowed.
 */
final class CopiedApiAccess
{
    private const OWNER_ONLY = '600';

    public function __construct(private DindProject $project) {}

    public function repointIn(string $runFile): void
    {
        $values = (new ApiAccess($this->project->system()))->envFor($this->project->userModel());
        $this->project->system()->filesystem()->assertNotSymlink($runFile);
        $this->project->composeWriter()->mergeApiAccessEnv($values, $runFile);
        $this->rewriteEnvOverrides(dirname($runFile).'/'.EngineArtifacts::ENV_OVERRIDES, $values);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function rewriteEnvOverrides(string $path, array $values): void
    {
        $fs = $this->project->system()->filesystem();
        if (! $fs->fileExists($path)) {
            return;
        }
        $fs->assertNotSymlink($path);
        $contents = $fs->fileGetContents($path);
        $updated = EnvFile::merge(self::withoutApiAccessKeys($contents), $values);
        if ($updated !== $contents) {
            $fs->writeFileReplacingPath($path, $updated, $this->project->userModel()->getChownString(), self::OWNER_ONLY);
        }
    }

    private static function withoutApiAccessKeys(string $contents): string
    {
        $rows = array_filter(
            EnvFile::parse($contents),
            static fn (array $row): bool => ! in_array($row['key'] ?? null, ApiAccess::ENV_KEYS, true)
        );

        return EnvFile::serialise(array_values($rows));
    }
}
