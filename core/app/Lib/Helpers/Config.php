<?php

namespace App\Lib\Helpers;

class Config
{
    public static function getIntValue(string $configName): ?int
    {
        /** @var mixed */
        $configValue = config($configName);
        if (filter_var($configValue, FILTER_VALIDATE_INT) !== false) {
            return (int) $configValue;
        }

        return null;
    }

    public static function getStringValue(string $configName): ?string
    {
        /** @var mixed */
        $configValue = config($configName);
        if (! empty($configValue) && is_scalar($configValue)) {
            return (string) $configValue;
        }

        return null;
    }
}
