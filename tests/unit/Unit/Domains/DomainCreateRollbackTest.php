<?php

namespace Tests\Unit\Domains;

use PHPUnit\Framework\TestCase;

/**
 * When domain:create fails after the Domain row is written -- in
 * syncServices() or proxyTo() -- the rollback has to undo what create() did,
 * as the REST path does. Deleting only the row leaves the vhost, the
 * certificate, the document root and the ProxyRules this command wrote as
 * orphans that a later domain of the same name picks up again.
 */
final class DomainCreateRollbackTest extends TestCase
{
    public function test_the_cli_rollback_removes_the_domain_before_the_row(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/core/app/Console/Commands/Domains/DomainsCreate.php');
        $this->assertIsString($source);

        $catchStart = strpos($source, 'catch (\\Throwable $e) {');
        $this->assertNotFalse($catchStart, 'DomainsCreate has no failure rollback');
        $this->assertStringContainsString('$this->rollBack($domain);', substr($source, $catchStart, 200), 'the failure path never rolls back');

        $rollbackStart = strpos($source, 'private function rollBack(Domain $domain)');
        $this->assertNotFalse($rollbackStart, 'DomainsCreate has no rollBack()');

        $rollback = substr($source, $rollbackStart, 800);
        $projectDomain = strpos($rollback, '$domain->projectDomain()->delete();');
        $row = strpos($rollback, '$domain->delete();');

        $this->assertNotFalse($projectDomain, 'the rollback never calls projectDomain()->delete()');
        $this->assertNotFalse($row, 'the rollback never deletes the row');
        $this->assertLessThan($row, $projectDomain, 'projectDomain()->delete() must undo the host side before the row goes');
    }
}
