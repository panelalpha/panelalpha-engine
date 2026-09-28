<?php

namespace Tests\Unit\System;

use App\System;
use App\System\EnginePaths;
use PHPUnit\Framework\TestCase;

class EnginePathsTest extends TestCase
{
    private function paths(): EnginePaths
    {
        return new EnginePaths('/engine', '/homes');
    }

    public function test_every_path_derives_from_the_two_roots(): void
    {
        $p = $this->paths();

        $this->assertSame('/engine', $p->engineDir());
        $this->assertSame('/homes', $p->homesDir());
        $this->assertSame('/engine/users', $p->projectsDir());
        $this->assertSame('/engine/templates', $p->templatesDir());
        $this->assertSame('/engine/docker-compose.yml', $p->composeFile());
        $this->assertSame('/engine/users/alice', $p->projectDir('alice'));
        $this->assertSame('/homes/alice', $p->projectHomeDir('alice'));
    }

    public function test_the_default_roots_are_the_production_ones(): void
    {
        $p = new EnginePaths();

        $this->assertSame('/opt/panelalpha/shared-hosting', $p->engineDir());
        $this->assertSame('/home', $p->homesDir());
    }

    public function test_a_template_without_a_name_is_the_default_one(): void
    {
        $p = $this->paths();

        $this->assertSame('/engine/templates/user/default/project', $p->projectFilesTemplateDir());
        $this->assertSame('/engine/templates/user/default/home', $p->projectHomeTemplateDir());
        $this->assertSame($p->projectFilesTemplateDir(), $p->projectFilesTemplateDir(null));
    }

    public function test_a_named_template_gets_its_own_directory(): void
    {
        $p = $this->paths();

        $this->assertSame('/engine/templates/user/wordpress/project', $p->projectFilesTemplateDir('wordpress'));
        $this->assertSame('/engine/templates/user/wordpress/home', $p->projectHomeTemplateDir('wordpress'));
    }

    /** A template that predates the per-template `domain/` directory falls back. */
    public function test_the_domain_template_falls_back_when_the_template_has_none(): void
    {
        $root = sys_get_temp_dir() . '/pa-paths-' . bin2hex(random_bytes(4));
        mkdir($root . '/templates/user/withdomain/domain', 0777, true);
        mkdir($root . '/templates/user/plain', 0777, true);

        $p = new EnginePaths($root, '/homes');

        $this->assertSame(
            $root . '/templates/user/withdomain/domain',
            $p->projectDomainTemplateDir('withdomain')
        );
        $this->assertSame(
            $root . '/templates/user-home',
            $p->projectDomainTemplateDir('plain'),
            'a template with no domain/ directory falls back to the shared one'
        );

        $this->removeTree($root);
    }

    /**
     * 44 test files subclass System to point it at a temporary tree. Every
     * derived path has to follow the override, which is why System builds an
     * EnginePaths from its own accessors rather than holding one.
     */
    public function test_a_system_subclass_that_moves_a_root_moves_every_derived_path(): void
    {
        $system = new class () extends System {
            public function engineDirPath(): string
            {
                return '/tmp/fake-engine';
            }

            public function homesDirPath(): string
            {
                return '/tmp/fake-homes';
            }
        };

        $this->assertSame('/tmp/fake-engine/users', $system->projectsDirPath());
        $this->assertSame('/tmp/fake-engine/users/alice', $system->projectDirPath('alice'));
        $this->assertSame('/tmp/fake-engine/templates', $system->templatesDirPath());
        $this->assertSame('/tmp/fake-engine/docker-compose.yml', $system->composeFilePath());
        $this->assertSame('/tmp/fake-homes/alice', $system->projectHomeDirPath('alice'));
        $this->assertSame(
            '/tmp/fake-engine/templates/user/default/project',
            $system->projectFilesTemplateDirPath()
        );
    }

    public function test_system_and_engine_paths_agree(): void
    {
        $system = new System();
        $p = $system->paths();

        $this->assertSame($system->engineDirPath(), $p->engineDir());
        $this->assertSame($system->homesDirPath(), $p->homesDir());
        $this->assertSame($system->projectsDirPath(), $p->projectsDir());
        $this->assertSame($system->composeFilePath(), $p->composeFile());
        $this->assertSame($system->projectDirPath('alice'), $p->projectDir('alice'));
        $this->assertSame($system->projectHomeDirPath('alice'), $p->projectHomeDir('alice'));
        $this->assertSame($system->templatesDirPath(), $p->templatesDir());
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
