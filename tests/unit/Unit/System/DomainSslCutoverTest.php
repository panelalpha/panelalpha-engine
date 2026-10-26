<?php

namespace Tests\Unit\System;

use PHPUnit\Framework\TestCase;

/**
 * Production cutover issue 06: domain/SSL callers use App\System\Project\Domain, not Models\Domain::connect().
 */
final class DomainSslCutoverTest extends TestCase
{
    public function test_domain_and_ssl_entry_layers_do_not_call_domain_connect(): void
    {
        $root = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'app';
        $paths = [
            'Http/Controllers/DomainController.php',
            'Http/Controllers/User/DomainController.php',
            'Http/Controllers/HttpAcmeChallengeController.php',
            'Console/Commands/Domains/DomainsCreate.php',
            'Console/Commands/Domains/DomainsDelete.php',
            'Console/Commands/Domains/DomainsSetProxy.php',
            'Console/Commands/Domains/DomainsUnsetProxy.php',
            'Console/Commands/System/PruneHttpAcmeChallenges.php',
            'Console/Commands/Users/RebuildDomains.php',
            'Console/Commands/Users/AddMissingWwwDomainAliases.php',
            'Console/Commands/Users/FixDomains.php',
            'Console/Commands/Ssl/ProjectCertRenew.php',
            'Lib/Ssl/ProjectCertificate.php',
            'Lib/Ssl/AcmeIssuer.php',
            'Lib/Ssl/Issuer.php',
            'Lib/Ssl/Issuers.php',
            'Lib/Ssl/SelfSignedIssuer.php',
            'Lib/Domains/PublicUrl.php',
        ];

        $hits = [];
        foreach ($paths as $relative) {
            $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $contents = file_get_contents($file);
            if ($contents === false) {
                $hits[] = $relative . ' (unreadable)';
                continue;
            }
            if (preg_match('/\$[a-zA-Z_]+->connect\(\)/', $contents) === 1) {
                $hits[] = $relative;
            }
            if (preg_match('/use App\\\\Lib\\\\Apis\\\\System\\\\User\\\\Domain;/', $contents) === 1) {
                $hits[] = $relative . ' (imports Lib User Domain)';
            }
        }

        $this->assertSame([], $hits, implode("\n", $hits));
    }

    public function test_models_domain_exposes_project_domain_helper(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/core/app/Models/Domain.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('function projectDomain(): System\\Project\\Domain', $source);
        $this->assertStringContainsString('->project()->domain($this)', $source);
        $this->assertStringNotContainsString('function connect()', $source);
    }
}
