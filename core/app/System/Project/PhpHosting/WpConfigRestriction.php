<?php

namespace App\System\Project\PhpHosting;

use App\Exceptions\MissingLinuxUserException;
use App\System\Privileged;
use App\System\Project\PhpHosting;

/**
 * Makes wp-config.php in each domain's document root and the folder above it readable by the project's user only.
 * The chmod runs as that user and never through a link, so a link planted at the name reaches nothing.
 */
final class WpConfigRestriction
{
    /** Group and other lose every bit; the owner's are left as they are, so a 400 file stays 400. */
    private const OWNER_ONLY = 'go=';

    public function __construct(
        private readonly PhpHosting $project,
    ) {}

    /**
     * @return int the files restricted; one that is missing or is a link is not
     *
     * @throws MissingLinuxUserException without the account's own user to leave them to
     */
    public function apply(): int
    {
        [$uid, $gid] = $this->project->userModel()->linuxIds();
        $account = Privileged::accountFiles($uid, $gid);
        $restricted = 0;
        foreach ($this->configFiles() as $file) {
            if ($this->project->system()->runProcess($account->changeMode(self::OWNER_ONLY, $file))->getExitCode() === 0) {
                $restricted++;
            }
        }

        return $restricted;
    }

    /** @return list<string> */
    private function configFiles(): array
    {
        $home = rtrim($this->project->project()->homeDirPath(), '/');
        $files = [];
        foreach ($this->project->userModel()->getDomains() as $domain) {
            $documentRoot = $home.$domain->getDocumentRoot();
            foreach ([$documentRoot, dirname($documentRoot)] as $dir) {
                if (str_starts_with($dir, $home.'/')) {
                    $files[] = $dir.'/wp-config.php';
                }
            }
        }

        return array_values(array_unique($files));
    }
}
