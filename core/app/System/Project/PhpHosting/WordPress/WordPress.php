<?php

namespace App\System\Project\PhpHosting\WordPress;

use App\Models\User;
use App\System;

/**
 * Install-scoped WordPress operations for a PHP hosting project.
 *
 * Optional path selects the install (null = main domain document root);
 * skipPlugins runs every operation with --skip-plugins --skip-themes. Named
 * modules use the shared WpCli; they do not resolve path or own a runner.
 */
final class WordPress
{
    public function __construct(
        private User $user,
        private WpCli $cli,
    ) {}

    public static function for(
        User $user,
        ?string $path = null,
        ?System $system = null,
        ?string $service = null,
        bool $skipPlugins = false,
    ): self {
        return new self($user, WpCli::for($user, $path, $system, $service, $skipPlugins));
    }

    public function cli(): WpCli
    {
        return $this->cli;
    }

    public function plugins(): Plugins
    {
        return new Plugins($this->cli);
    }

    public function themes(): Themes
    {
        return new Themes($this->cli);
    }

    public function users(): Users
    {
        return new Users($this->cli);
    }

    public function applicationPasswords(): ApplicationPasswords
    {
        return new ApplicationPasswords($this->cli);
    }

    public function core(): Core
    {
        return new Core($this->cli);
    }

    public function cache(): Cache
    {
        return new Cache($this->cli);
    }

    public function maintenance(): Maintenance
    {
        return new Maintenance($this->cli);
    }

    public function config(): Config
    {
        return new Config($this->cli);
    }

    public function autoUpdate(): AutoUpdate
    {
        return new AutoUpdate($this->cli);
    }

    public function options(): Options
    {
        return new Options($this->cli);
    }

    public function searchReplace(): SearchReplace
    {
        return new SearchReplace($this->cli);
    }

    public function language(): Language
    {
        return new Language($this->cli);
    }

    public function database(): Database
    {
        return new Database($this->cli);
    }

    public function sso(): Sso
    {
        return new Sso($this->cli, $this->user);
    }
}
