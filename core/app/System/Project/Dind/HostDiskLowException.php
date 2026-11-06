<?php

namespace App\System\Project\Dind;

use App\Lib\Findings\Finding;
use App\Lib\Findings\FindingException;

/** A deploy refused before it started, because the engine host is short of disk. */
final class HostDiskLowException extends \RuntimeException implements FindingException
{
    public const CODE = 'host_disk_low';

    public function __construct(string $message, public readonly string $path)
    {
        parent::__construct($message);
    }

    public function finding(): Finding
    {
        return new Finding(self::CODE, $this->getMessage(), ['path' => $this->path]);
    }
}
