<?php

namespace App\Lib\Vault;

use App\Exceptions\ProblemException;
use App\Lib\Git\DeployKey;
use App\Models\SecretVaultEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SSH deploy keys the vault generates, so a project with an SSH remote is created in one call; like every vault secret
 * the key is read once, by the project it is given to, within its scope.
 */
class VaultDeployKeys
{
    public const REFERENCE_INVALID = 'deploy_key_reference_invalid';

    public function __construct(private readonly DeployKey $keys) {}

    /**
     * A new key, with `$host`'s SSH host keys pinned when it is a git server
     * other than the three whose keys ship with the engine.
     *
     * @throws ValidationException|ProblemException
     */
    public function mint(string $scope, ?string $purpose, ?string $project, ?string $host, ?int $expiresIn): SecretVaultEntry
    {
        SecretMinter::assertExpiry($expiresIn);
        $project = SecretMinter::ownerOf($project, $scope);

        $key = $this->keys->generate('panelalpha-'.($project ?? 'vault-'.Str::lower(Str::random(6))));
        $key = $this->pinned($key, self::trimmedOrNull($host));

        return $this->store($key, $scope, self::trimmedOrNull($purpose), $project, $expiresIn);
    }

    /**
     * The key a `vault:` reference names, for project `$project`; the entry is
     * claimed by it as any project secret is.
     *
     * @return array{private_key: string, public_key: string, fingerprint: string, known_hosts: array<string, list<string>>}
     *
     * @throws ValidationException naming `$field`
     */
    public static function resolve(string $field, string $reference, string $project): array
    {
        if (! RequestVault::isReference($reference)) {
            throw ProblemException::one($field, self::REFERENCE_INVALID, 'A deploy key is passed as the vault:<key> reference the vault returned when it was created.');
        }

        $key = json_decode((string) RequestVault::resolve($field, $reference, $project, SecretVaultEntry::TYPE_DEPLOY_KEY), true);
        if (! DeployKey::isKey($key)) {
            throw ValidationException::withMessages([$field => 'Vault entry '.trim($reference).' does not hold a usable deploy key. Create a new one.']);
        }
        $key['fingerprint'] = is_string($key['fingerprint'] ?? null) ? $key['fingerprint'] : DeployKey::fingerprint($key['public_key']);
        $key['known_hosts'] = (array) ($key['known_hosts'] ?? []);

        return $key;
    }

    /**
     * @param  array{private_key: string, public_key: string, fingerprint: string, known_hosts: array<string, list<string>>}  $key
     * @return array{private_key: string, public_key: string, fingerprint: string, known_hosts: array<string, list<string>>}
     */
    private function pinned(array $key, ?string $host): array
    {
        if ($host === null) {
            return $key;
        }

        $name = DeployKey::knownHostsName($host);
        if (! DeployKey::pinsIn($key, $name)) {
            $key['known_hosts'][$name] = $this->keys->scan($name);
        }

        return $key;
    }

    /**
     * @param  array{private_key: string, public_key: string, fingerprint: string, known_hosts: array<string, list<string>>}  $key
     */
    private function store(array $key, string $scope, ?string $purpose, ?string $project, ?int $expiresIn): SecretVaultEntry
    {
        $entry = new SecretVaultEntry([
            'ref_hash' => SecretVaultEntry::hashRef(Str::random(48)),
            'type' => SecretVaultEntry::TYPE_DEPLOY_KEY,
            'scope' => $scope,
            'purpose' => $purpose,
            'project' => $project,
            'expires_at' => $expiresIn === null ? null : Carbon::now()->addSeconds($expiresIn),
            'link_expires_at' => Carbon::now(),
            'public_part' => DeployKey::view($key),
        ]);
        $entry->setSecret((string) json_encode($key));
        $entry->save();

        return $entry;
    }

    private static function trimmedOrNull(?string $value): ?string
    {
        return ($trimmed = trim((string) $value)) !== '' ? $trimmed : null;
    }
}
