<?php

namespace App\Http\Requests\Concerns;

use App\Lib\Vault\RequestVault;
use App\Rules\EximSecret;
use App\Rules\UpstreamHost;

/**
 * The Exim config, saved by PUT /system/exim-config and by a test email's `config`.
 */
trait ValidatesEximConfig
{
    /**
     * The mail credentials, which may be a `vault:<key>`.
     *
     * @var list<string>
     */
    private const EXIM_SECRET_FIELDS = [
        'sendgrid_api_token',
        'mailchannels_username',
        'mailchannels_password',
        'amazon_ses_smtp_username',
        'amazon_ses_smtp_password',
        'smtp_username',
        'smtp_password',
    ];

    /**
     * `digits_between` refuses " 587" and "587\n", which `integer` alone takes and MCP passes
     * through untrimmed; `integer` stays so `between` compares the number.
     */
    private const EXIM_PORT_RULE = 'string|nullable|digits_between:1,5|integer|between:1,65535';

    /**
     * Resolves `vault:<key>` mail credentials. Runs before validation, so EximSecret checks the secret that reaches passwd.client.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function resolveEximSecrets(array $params): array
    {
        foreach (self::EXIM_SECRET_FIELDS as $field) {
            if (array_key_exists($field, $params)) {
                $params[$field] = RequestVault::resolve($field, $params[$field], null);
            }
        }

        return $params;
    }

    /**
     * Hosts, ports and the sender domain end up in update-exim4.conf.conf, which
     * update-exim4.conf pastes into a sed expression: a `|` there broke the apply
     * with a raw sed error. `smtp_host` takes no port, since smtp_port is appended
     * to it as `::<port>`.
     *
     * @return array<string, mixed>
     */
    private static function eximConfigRules(string $prefix = ''): array
    {
        $host = ['string', 'nullable', new UpstreamHost];
        $port = self::EXIM_PORT_RULE;
        $secret = ['string', 'nullable', new EximSecret];
        $rules = [
            'smarthost_provider' => 'string|nullable',
            'sendgrid_api_token' => $secret,
            'mailchannels_username' => $secret,
            'mailchannels_password' => $secret,
            'amazon_ses_smtp_endpoint' => $host,
            'amazon_ses_starttls_port' => $port,
            'amazon_ses_smtp_username' => $secret,
            'amazon_ses_smtp_password' => $secret,
            'smtp_host' => $host,
            'smtp_port' => $port,
            'smtp_username' => $secret,
            'smtp_password' => $secret,
            'smtp_implicit_tls' => 'boolean|nullable',
            'sender_domain' => [...$host, self::senderDomainIsNotAnAddress(...)],
        ];

        $prefixed = [];
        foreach ($rules as $field => $rule) {
            $prefixed[$prefix.$field] = $rule;
        }

        return $prefixed;
    }

    /** Senders are rewritten to this domain, and an IP address is not one. */
    private static function senderDomainIsNotAnAddress(string $attribute, mixed $value, \Closure $fail): void
    {
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false) {
            $fail('The :attribute must be a domain name, not an IP address.');
        }
    }

    /**
     * `smtp.example.com.` names the same host and was accepted before; it is
     * stored without the dot, so the hostname rule and Exim's readhost take it.
     */
    private function withoutTrailingDots(?string $under = null): void
    {
        $input = $under === null ? $this->all() : $this->input($under);
        if (! is_array($input)) {
            return;
        }
        foreach (['smtp_host', 'amazon_ses_smtp_endpoint', 'sender_domain'] as $field) {
            $value = $input[$field] ?? null;
            if (is_string($value) && strlen($value) > 1 && str_ends_with($value, '.')) {
                $input[$field] = substr($value, 0, -1);
            }
        }
        $this->merge($under === null ? $input : [$under => $input]);
    }
}
