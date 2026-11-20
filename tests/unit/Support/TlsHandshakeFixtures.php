<?php

namespace Tests\Support;

trait TlsHandshakeFixtures
{
    /** @var resource|null */
    private $server = null;

    private string $dir = '';

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir.'/*') ?: []);
            @rmdir($this->dir);
        }
        parent::tearDown();
    }
}
