<?php

namespace Tests\Unit\System\Webserver;

use App\System;
use App\System\Filesystem;
use App\System\Services\Webserver\Openlitespeed;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

// #48 item 22: the serial number was written without the check LiteSpeed runs.
class OpenlitespeedSerialNumberTest extends TestCase
{
    public function test_a_serial_that_fails_validation_is_never_installed(): void
    {
        $written = [];
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('filePutContents')
            ->andReturnUsing(function (string $path) use (&$written) {
                $written[] = basename($path);
            });
        $filesystem->shouldReceive('ls')->never();

        $system = Mockery::mock(System::class);
        $system->shouldReceive('engineDirPath')->andReturn('/opt/engine');
        $system->shouldReceive('filesystem')->andReturn($filesystem);
        $system->shouldReceive('exec')->once()->andThrow(new \RuntimeException('lshttpd: invalid serial'));

        try {
            (new Openlitespeed($system))->updateConfig('serial_number', 'not-a-serial');
            $this->fail('An invalid serial number was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serial_number', $e->errors());
        }

        $this->assertSame(['serial.no.tmp'], $written);
    }
}
