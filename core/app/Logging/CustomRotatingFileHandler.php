<?php

namespace App\Logging;

use App\System;
use Monolog\Handler\RotatingFileHandler;
use Monolog\LogRecord;

class CustomRotatingFileHandler extends RotatingFileHandler
{
    protected function write(LogRecord $record): void
    {
        try {
            parent::write($record);
        } catch (\Exception $e) {
            if ($logFile = $this->getUrl()) {
                $system = new System;
                $system->runProcess([
                    'sudo',
                    'chown',
                    '-R',
                    'www-data:www-data',
                    dirname($logFile),
                ]);
                parent::write($record);

                return;
            }
            throw $e;
        }
    }
}
