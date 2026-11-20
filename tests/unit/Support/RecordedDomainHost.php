<?php

namespace Tests\Support;

use App\Models\Domain as DomainModel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Domain as ProjectDomain;
use App\System\Services\Webserver;
use ArrayObject;
use RuntimeException;

/**
 * The host side of a project's domains as a command sees it, recorded instead of
 * run: vhost create, rebuild and delete, the project's PHP handlers and
 * services, and the webserver reload. A step starting with one of `failOn` throws.
 */
class RecordedDomainHost
{
    /** @var ArrayObject<int, string> */
    public ArrayObject $calls;

    private RecordingSystem $system;

    /** @var list<string> */
    private array $failOn;

    public function __construct(string ...$failOn)
    {
        $this->failOn = array_values($failOn);
        $this->calls = new ArrayObject();
        $this->system = new RecordingSystem();
    }

    /** @return list<string> */
    public function recorded(): array
    {
        return $this->calls->getArrayCopy();
    }

    public function domain(DomainModel $model): ProjectDomain
    {
        $host = $this;

        return new class($this->project($model->user), $model, $host) extends ProjectDomain
        {
            public function __construct(Project $project, DomainModel $model, private readonly RecordedDomainHost $host)
            {
                parent::__construct($project, $model);
            }

            public function create(): void
            {
                $this->host->record('create '.$this->model()->domain);
            }

            public function rebuild(): void
            {
                $this->host->record('rebuild '.$this->model()->domain);
            }

            public function delete(bool $keepCertificate = false): void
            {
                $this->host->record('delete '.$this->model()->domain);
            }
        };
    }

    public function project(User $user): Project
    {
        $host = $this;

        return new class($this->system, $user, $host) extends Project
        {
            public function __construct(System $system, User $user, private readonly RecordedDomainHost $host)
            {
                parent::__construct($system, $user);
            }

            public function syncPhpHandlersScripts(): void
            {
                $this->host->record('php handlers '.$this->model()->username);
            }

            public function syncServices(): void
            {
                $this->host->record('services '.$this->model()->username);
            }
        };
    }

    /** A System whose webserver records the background reload it is asked to schedule. */
    public function system(): System
    {
        $host = $this;

        return new class($host) extends RecordingSystem
        {
            public function __construct(private readonly RecordedDomainHost $host) {}

            public function webserver(): Webserver
            {
                $host = $this->host;

                return new class($this, $host) extends Webserver
                {
                    public function __construct(System $system, private readonly RecordedDomainHost $host)
                    {
                        parent::__construct($system);
                    }

                    public function scheduleWebserverReloadInBackground(bool $rebindIpListeners = false): void
                    {
                        $this->host->record('webserver reload');
                    }
                };
            }
        };
    }

    public function record(string $call): void
    {
        $this->calls[] = $call;
        foreach ($this->failOn as $step) {
            if (str_starts_with($call, $step)) {
                throw new RuntimeException("{$call} failed");
            }
        }
    }
}
