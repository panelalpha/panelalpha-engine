<?php

// Prints the key Passwordcockpit encrypts stored passwords and files with
// (PASSWORDCOCKPIT_BLOCK_CIPHER_KEY). The repository's compose file sets
// `supersecretkey`, the same on every install, so a vault started under this
// recipe uses the engine's generated PASSWORDCOCKPIT_VAULT_KEY instead.
//
// A vault that already holds an encrypted password or file is encrypted with
// the published key, and a new key would make it unreadable: it keeps that
// key. The choice is made once and written next to the uploads, on the same
// volume as the vault's files, so it is never made again for this vault.
const PANELALPHA_VAULT_MARKER = '/var/www/html/upload/.panelalpha-vault-key-source';

/**
 * @return array{0: string, 1: ?string} the key, and the source to record (null: record nothing)
 */
function panelalpha_vault_key(?string $recorded, ?int $encrypted, string $generated, string $published): array
{
    if ($recorded === 'generated') {
        if ($generated === '') {
            throw new RuntimeException('the vault is encrypted with the generated key, and PASSWORDCOCKPIT_VAULT_KEY is empty');
        }

        return [$generated, null];
    }
    if ($recorded === 'published' || $generated === '' || $encrypted === null) {
        return [$published, null];
    }

    return $encrypted > 0 ? [$published, 'published'] : [$generated, 'generated'];
}

function panelalpha_encrypted_entries(): ?int
{
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8', getenv('PASSWORDCOCKPIT_DATABASE_HOSTNAME'), getenv('PASSWORDCOCKPIT_DATABASE_DATABASE')),
            (string) getenv('PASSWORDCOCKPIT_DATABASE_USERNAME'),
            (string) getenv('PASSWORDCOCKPIT_DATABASE_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        return (int) $pdo->query("SELECT (SELECT COUNT(*) FROM password WHERE password IS NOT NULL AND password <> '') + (SELECT COUNT(*) FROM file)")->fetchColumn();
    } catch (Throwable $e) {
        fwrite(STDERR, '[panelalpha] passwordcockpit: could not count the vault entries: '.$e->getMessage()."\n");

        return null;
    }
}

if (PHP_SAPI !== 'cli' || realpath((string) ($_SERVER['argv'][0] ?? '')) !== __FILE__) {
    return;
}
ini_set('display_errors', 'stderr');

$recorded = is_file(PANELALPHA_VAULT_MARKER) ? trim((string) file_get_contents(PANELALPHA_VAULT_MARKER)) : null;
$generated = (string) getenv('PASSWORDCOCKPIT_VAULT_KEY');
$published = (string) getenv('PASSWORDCOCKPIT_BLOCK_CIPHER_KEY');
try {
    [$key, $record] = panelalpha_vault_key($recorded, $recorded === null && $generated !== '' ? panelalpha_encrypted_entries() : null, $generated, $published);
} catch (RuntimeException $e) {
    fwrite(STDERR, '[panelalpha] passwordcockpit: '.$e->getMessage()."\n");
    exit(1);
}
if ($record !== null && file_put_contents(PANELALPHA_VAULT_MARKER, $record."\n") === false) {
    [$key, $record] = [$published, null];
}
if ($record === 'published') {
    fwrite(STDERR, "[panelalpha] passwordcockpit: this vault was encrypted with the repository's published key and keeps it\n");
}
if ($record === 'generated') {
    fwrite(STDERR, "[panelalpha] passwordcockpit: the vault is encrypted with the key the engine generated\n");
}
echo $key;
