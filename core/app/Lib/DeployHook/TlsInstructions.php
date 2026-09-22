<?php

namespace App\Lib\DeployHook;

/**
 * What to tell a client about getting a git host to call a self-signed
 * engine's Deploy Hook, one paragraph per provider.
 *
 * Bitbucket Cloud has no per-webhook override -- it always verifies the
 * target's certificate -- so its entry does not say "disable verification"
 * like the other three; it says what the engine already offers instead
 * (a trusted domain certificate).
 */
final class TlsInstructions
{
    public const GITHUB = 'github';
    public const GITLAB = 'gitlab';
    public const BITBUCKET_CLOUD = 'bitbucket-cloud';
    public const BITBUCKET_DATA_CENTER = 'bitbucket-data-center';

    /** @var array<string, string> */
    private const TEXT = [
        self::GITHUB => 'On the webhook\'s settings page (repository or organization Settings > Webhooks > this webhook), '
            . 'set "SSL verification" to Disable, then save. This applies to this one webhook only.',
        self::GITLAB => 'When adding or editing the webhook (Settings > Webhooks), clear the "Enable SSL verification" checkbox before saving.',
        self::BITBUCKET_CLOUD => 'Bitbucket Cloud always verifies the certificate a webhook URL presents and has no setting to skip that. '
            . 'A self-signed engine certificate will make every delivery fail here: give the engine a trusted certificate for a '
            . 'domain first (see the getting-started TLS guide), then register the webhook against that domain.',
        self::BITBUCKET_DATA_CENTER => 'When adding the webhook, open its advanced settings and disable certificate verification for '
            . 'this webhook (older Bitbucket Data Center releases do not offer this option and need a trusted certificate instead, '
            . 'the same as Bitbucket Cloud).',
    ];

    /**
     * Every provider's instructions, in the order they are documented.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::TEXT;
    }

    /** Null for a provider this list does not cover. */
    public static function for(string $provider): ?string
    {
        return self::TEXT[$provider] ?? null;
    }

    /** @return list<string> every provider slug this class has instructions for */
    public static function providers(): array
    {
        return array_keys(self::TEXT);
    }
}
