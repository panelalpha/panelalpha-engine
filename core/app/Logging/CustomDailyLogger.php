<?php

namespace App\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\Logger;

class CustomDailyLogger
{
    public function __invoke(array $config)
    {
        $filename = storage_path('logs/laravel.log');
        if (! empty($config['path']) && is_string($config['path'])) {
            $filename = $config['path'];
        }
        $maxFiles = 30;
        if (! empty($config['days']) && is_int($config['days'])) {
            $maxFiles = $config['days'];
        }
        $level = 'debug';
        if (! empty($config['level']) && is_string($config['level'])) {
            $level = $config['level'];
        }
        $handler = new CustomRotatingFileHandler($filename, $maxFiles, $level);
        $formatter = new LineFormatter(null, 'Y-m-d H:i:s', true, true);
        $handler->setFormatter($formatter);
        $logger = new Logger('daily');
        $logger->pushHandler($handler);

        return $logger;
    }
}
