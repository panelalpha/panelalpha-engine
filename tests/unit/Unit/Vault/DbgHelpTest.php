<?php
namespace Tests\Unit\Vault;
use App\Models\SecretVaultEntry;

class DbgHelpTest extends VaultTestCase
{
    public function test_dbg(): void
    {
        $r = new \ReflectionMethod(SecretVaultEntry::class, 'helpFileName');
        $r->setAccessible(true);
        var_dump('helpFileName: ' . $r->invoke(null, 'git_token'));
        $out = SecretVaultEntry::help('git_token');
        var_dump('len: ' . strlen($out) . ' starts: ' . substr($out, 0, 40));
        $this->assertTrue(true);
    }
}