<?php

namespace Tests\Unit\System\Project;

use App\Models\User;
use App\System;
use App\System\Project\Ftp;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** A refused FTP directory is a validation error on `directory`, as a client sent it. */
class FtpDirectoryRefusalTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir() . '/pa-ftp-' . bin2hex(random_bytes(4));
        mkdir($this->home . '/site', 0777, true);
        symlink('/etc', $this->home . '/escape');
    }

    protected function tearDown(): void
    {
        unlink($this->home . '/escape');
        rmdir($this->home . '/site');
        rmdir($this->home);
        parent::tearDown();
    }

    public function test_a_link_out_of_the_home_is_refused_under_directory(): void
    {
        $this->routeCreatingInto('escape');

        $response = $this->postJson('/api/test-ftp-directory');

        $response->assertStatus(422);
        $response->assertJsonPath('errors.directory.0', 'The directory must be an existing directory inside the project home.');
        $response->assertJsonPath('problems.0.field', 'directory');
        $response->assertJsonPath('problems.0.code', 'directory_invalid');
    }

    public function test_a_missing_directory_is_refused_under_directory(): void
    {
        $this->routeCreatingInto('nowhere');

        $this->postJson('/api/test-ftp-directory')
            ->assertStatus(422)
            ->assertJsonPath('problems.0.field', 'directory');
    }

    private function routeCreatingInto(string $directory): void
    {
        $ftp = new Ftp($this->system()->project(User::make(['username' => 'alice'])));
        Route::post('/api/test-ftp-directory', static function () use ($ftp, $directory): void {
            $ftp->create('ftp1@alice.example.test', 'secret-password', '/' . $directory);
        });
    }

    private function system(): System
    {
        return new class ($this->home) extends System {
            public function __construct(private string $home)
            {
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->home;
            }
        };
    }
}
