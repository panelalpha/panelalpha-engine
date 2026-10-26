<?php

namespace App\Lib\Mail;

/**
 * The mail passwords and API tokens a read of the mail settings never returns.
 */
final class EximConfigSecrets
{
    /** @var list<string> */
    public const HIDDEN_FIELDS = [
        'sendgrid_api_token',
        'mailchannels_password',
        'amazon_ses_smtp_password',
        'smtp_password',
    ];

    /**
     * The config with each hidden secret as null and a `<field>_set` flag saying whether one is stored.
     *
     * @param  array<string, string>  $config
     * @return array<string, mixed>
     */
    public static function masked(array $config): array
    {
        foreach (self::HIDDEN_FIELDS as $field) {
            $config[$field.'_set'] = ($config[$field] ?? '') !== '';
            $config[$field] = null;
        }

        return $config;
    }

    /**
     * A hidden secret sent empty keeps its stored value: the read answers each as null, and that
     * answer sent back must not erase them. `clear_secrets` is the way to clear one.
     *
     * @param  array<array-key, mixed>  $config
     * @return array<array-key, mixed>
     */
    public static function withoutEmpty(array $config): array
    {
        foreach (self::HIDDEN_FIELDS as $field) {
            if (array_key_exists($field, $config) && ($config[$field] === null || $config[$field] === '')) {
                unset($config[$field]);
            }
        }

        return $config;
    }
}
