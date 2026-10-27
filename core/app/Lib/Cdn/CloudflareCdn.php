<?php

namespace App\Lib\Cdn;

use App\Exceptions\ProblemException;
use App\Lib\Apis\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Apis\Cloudflare\ZoneNotFoundException;
use App\Lib\Domains\ProjectHostnames;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;

/**
 * Cloudflare's cache and zone settings for one of a project's hostnames, read
 * and changed with the project's own Cloudflare API token, which only a
 * container-based project can hold. A zone-wide change is refused while the
 * zone also serves another project on this engine. A token Cloudflare does not
 * accept, a refused permission or a refused request is a ProblemException that
 * says which; anything else is a {@see CdnFailure}.
 */
final class CloudflareCdn
{
    private const SCOPE_ZONE_READ = 'Zone Read';

    private const SCOPE_CACHE_PURGE = 'Zone Cache Purge';

    private const SCOPE_SETTINGS_READ = 'Zone Settings Read';

    private const SCOPE_DNS_READ = 'Zone DNS Read';

    private const SCOPE_SETTINGS_EDIT = 'Zone Settings Edit';

    /**
     * The zone and settings Cloudflare holds for the hostname.
     *
     * @return array{provider: string, zone_id: string, zone_name: string, proxied: bool, security_level: string, development_mode: bool}
     */
    public function status(User $user, string $hostname): array
    {
        $client = $this->clientFor($user);
        $zone = $this->zoneFor($client, $hostname);

        $record = $this->guard(
            self::SCOPE_DNS_READ,
            fn (): ?array => $client->findDnsRecord($zone['id'], $hostname)
        );

        return [
            'provider' => 'cloudflare',
            'zone_id' => $zone['id'],
            'zone_name' => $zone['name'],
            'proxied' => (bool) ($record['proxied'] ?? false),
            'security_level' => $this->setting($client, $zone['id'], 'security_level'),
            'development_mode' => self::isOn($this->setting($client, $zone['id'], 'development_mode')),
        ];
    }

    /**
     * Purge the zone's whole cache, or only the listed files.
     *
     * @param  list<string>  $files
     */
    public function purge(User $user, string $hostname, bool $everything, array $files): void
    {
        $client = $this->clientFor($user);
        $zone = $this->zoneFor($client, $hostname);
        if ($everything) {
            $this->refuseSharedZone(
                $user,
                $zone,
                'everything',
                "so the whole zone cannot be purged from this project. Purge this project's URLs with `files` instead."
            );
        }

        $this->guard(
            self::SCOPE_CACHE_PURGE,
            fn () => $client->purgeCache($zone['id'], $everything, $everything ? [] : $files)
        );
    }

    /**
     * Set the zone's security level, and answer the level Cloudflare now holds.
     */
    public function setSecurityLevel(User $user, string $hostname, string $level): string
    {
        $client = $this->clientFor($user);
        $zone = $this->zoneFor($client, $hostname);
        $this->refuseSharedZone(
            $user,
            $zone,
            'level',
            'and the security level applies to them too. Change it in the Cloudflare dashboard instead.'
        );

        return $this->guard(
            self::SCOPE_SETTINGS_EDIT,
            fn (): string => $client->setSetting($zone['id'], 'security_level', $level)
        );
    }

    private function clientFor(User $user): Cloudflare
    {
        $token = $user->getCloudflareApiToken();
        if ($token === null) {
            throw ProblemException::one(
                'cloudflare_api_token',
                'cloudflare_token_missing',
                "Project '{$user->username}' has no Cloudflare API token. Save one on the project "
                .'before using the CDN.'
            );
        }

        return new Cloudflare($token);
    }

    /**
     * @return array{id: string, name: string}
     */
    private function zoneFor(Cloudflare $client, string $hostname): array
    {
        try {
            return $client->findZoneForHostname($hostname);
        } catch (ZoneNotFoundException) {
            throw CdnFailure::zoneNotFound($hostname);
        } catch (CloudflareException|ConnectionException $e) {
            throw $this->failure($e, self::SCOPE_ZONE_READ);
        }
    }

    /**
     * A zone-wide change reaches every hostname in the zone, so it is refused while another
     * project on this engine has one there.
     *
     * @param  array{id: string, name: string}  $zone
     */
    private function refuseSharedZone(User $user, array $zone, string $field, string $consequence): void
    {
        if (! ProjectHostnames::zoneServesAnotherProject($user, $zone['name'])) {
            return;
        }

        throw ProblemException::conflict(
            $field,
            'cloudflare_zone_shared',
            "Cloudflare zone '{$zone['name']}' also serves other projects on this engine, {$consequence}"
        );
    }

    private function setting(Cloudflare $client, string $zoneId, string $name): string
    {
        return $this->guard(self::SCOPE_SETTINGS_READ, fn (): string => $client->getSetting($zoneId, $name));
    }

    private function guard(string $scope, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (CloudflareException|ConnectionException $e) {
            throw $this->failure($e, $scope);
        }
    }

    /**
     * A refused permission or request is the caller's to fix (422); a rate limit
     * or a Cloudflare failure is worth retrying later (503, 502).
     */
    private function failure(CloudflareException|ConnectionException $e, string $scope): \Throwable
    {
        if ($e instanceof ConnectionException) {
            return CdnFailure::unavailable('Cloudflare could not be reached. Try again later.');
        }

        return match (true) {
            $e->isInvalidToken() => $this->invalidToken($e),
            $e->isPermissionDenied() => $this->permissionDenied($scope),
            $e->isRateLimited() => CdnFailure::rateLimited(),
            $e->isRefusedRequest() => ProblemException::one(
                'cloudflare_api_token',
                'cloudflare_request_refused',
                $e->getMessage()
            ),
            default => CdnFailure::unavailable($e->getMessage()),
        };
    }

    private function invalidToken(CloudflareException $e): ProblemException
    {
        return ProblemException::one(
            'cloudflare_api_token',
            'cloudflare_token_invalid',
            "The project's Cloudflare API token is invalid or revoked ({$e->getMessage()}). "
            .'Save a current token on the project.'
        );
    }

    private function permissionDenied(string $scope): ProblemException
    {
        return ProblemException::one(
            'cloudflare_api_token',
            'cloudflare_permission_denied',
            "The Cloudflare API token is missing the {$scope} permission for this zone."
        );
    }

    /**
     * Cloudflare spells the zone's development mode `on` and `off`.
     */
    private static function isOn(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
