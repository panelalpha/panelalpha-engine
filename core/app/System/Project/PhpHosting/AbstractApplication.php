<?php

namespace App\System\Project\PhpHosting;

use App\Models\Domain;
use App\System\Project\AbstractApplication as BaseApplication;
use App\System\Project\PhpHosting;

/**
 * One PHP hosting application identified by a document-root path.
 */
final class AbstractApplication extends BaseApplication
{
    /**
     * @param  list<Domain>  $domains
     */
    public function __construct(
        private PhpHosting $runtime,
        private string $documentRoot,
        private array $domains,
    ) {}

    public function id(): string
    {
        return 'php:'.$this->runtime->username().':'.$this->documentRoot;
    }

    public function rootPath(): string
    {
        return $this->runtime->system()->projectHomeDirPath($this->runtime->username())
            .$this->documentRoot;
    }

    public function documentRoot(): string
    {
        return $this->documentRoot;
    }

    /**
     * @return list<Domain>
     */
    public function domains(): array
    {
        return $this->domains;
    }

    public function php(): PhpRuntime
    {
        return new PhpRuntime($this->runtime);
    }

    /**
     * @param  list<string>  $args
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function runWpCli(array $args): array
    {
        return $this->php()->runWpCli($args);
    }
}
