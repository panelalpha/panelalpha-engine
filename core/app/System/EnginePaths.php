<?php

namespace App\System;

/**
 * Every path the engine derives from its two roots.
 *
 * Pulled out of {@see \App\System} because it is the half of that class with no
 * I/O in it at all, and because it is the half 44 test files were subclassing
 * System to get at.
 *
 * System still owns the two roots and stays the overridable seam: it builds one
 * of these from its own accessors, so a subclass that redefines a root still
 * changes every path derived from it.
 */
final class EnginePaths
{
    public const ENGINE_DIR = '/opt/panelalpha/shared-hosting';
    public const HOMES_DIR = '/home';

    public function __construct(
        private readonly string $engineDir = self::ENGINE_DIR,
        private readonly string $homesDir = self::HOMES_DIR,
    ) {
    }

    public function engineDir(): string
    {
        return $this->engineDir;
    }

    public function homesDir(): string
    {
        return $this->homesDir;
    }

    public function projectsDir(): string
    {
        return $this->engineDir . '/users';
    }

    public function templatesDir(): string
    {
        return $this->engineDir . '/templates';
    }

    public function composeFile(): string
    {
        return $this->engineDir . '/docker-compose.yml';
    }

    public function projectDir(string $username): string
    {
        return $this->projectsDir() . '/' . $username;
    }

    public function projectHomeDir(string $username): string
    {
        return $this->homesDir . '/' . $username;
    }

    public function projectFilesTemplateDir(?string $template = null): string
    {
        return $this->templateDir($template) . '/project';
    }

    public function projectHomeTemplateDir(?string $template = null): string
    {
        return $this->templateDir($template) . '/home';
    }

    /**
     * Templates that predate the per-template `domain/` directory fall back to
     * the shared `user-home` one, so an older install keeps working.
     */
    public function projectDomainTemplateDir(?string $template = null): string
    {
        $perTemplate = $this->templateDir($template) . '/domain';

        return is_dir($perTemplate) ? $perTemplate : $this->templatesDir() . '/user-home';
    }

    private function templateDir(?string $template): string
    {
        return $this->templatesDir() . '/user/' . ($template ?? 'default');
    }
}
