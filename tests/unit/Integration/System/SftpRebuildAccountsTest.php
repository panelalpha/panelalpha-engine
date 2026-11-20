<?php

namespace Tests\Integration\System;

use App\Models\SftpAccount;
use App\System\Privileged;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** `rebuildSftpAccounts()` runs the host commands of every project through the System its collaborator was built with. */
class SftpRebuildAccountsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_the_linux_identity_is_read_through_the_injected_system(): void
    {
        $user = $this->makeUser('alice');
        SftpAccount::create(['user_id' => $user->id, 'username' => 'alice_sftp', 'auth_method' => 'password', 'password' => 'secret']);
        $system = new RecordingSystem;
        $system->output = "1001\n";

        $system->sftp()->rebuildSftpAccounts();

        $this->assertContains(Privileged::users()->uid('alice'), $system->commands);
        $this->assertContains(Privileged::users()->gid('alice'), $system->commands);
    }
}
