<?php

namespace Tests\Unit\System\Project;

use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Services\Webserver;
use Tests\TestCase;

/** A domain the webserver has not written logs for yet lists none instead of failing. */
class DomainLogFilesMissingDirTest extends TestCase
{
    public function test_a_domain_without_a_log_directory_lists_no_files(): void
    {
        $this->assertSame([], $this->domain()->listLogFiles());
    }

    public function test_the_all_webservers_listing_agrees(): void
    {
        $this->assertSame([], $this->domain()->listWebserverLogFiles());
    }

    /** The guard lives in logFileNames() itself, so a caller that skips its own check still warns nothing. */
    public function test_log_file_names_of_a_missing_directory_is_empty_without_a_warning(): void
    {
        $method = new \ReflectionMethod(Project\Domain::class, 'logFileNames');
        $warnings = [];
        set_error_handler(function (int $number, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $names = $method->invoke(null, sys_get_temp_dir() . '/pa-none-' . bin2hex(random_bytes(4)));
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $names);
        $this->assertSame([], $warnings);
    }

    private function domain(): Project\Domain
    {
        $user = new ModelsUser();
        $user->username = 'alice';
        $model = new DomainModel();
        $model->domain = 'no-logs-yet.test';

        return (new Project($this->system(), $user))->domain($model);
    }

    private function system(): System
    {
        return new class (sys_get_temp_dir() . '/pa-no-logs-' . bin2hex(random_bytes(4))) extends System {
            public function __construct(private string $root)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            public function webserver(): Webserver
            {
                return new class ($this) extends Webserver {
                    public function getCurrentWebserver(): string
                    {
                        return 'nginx-proxy';
                    }
                };
            }
        };
    }
}
