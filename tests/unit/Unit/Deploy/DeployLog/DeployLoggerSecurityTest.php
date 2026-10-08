<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Exceptions\DeployAlreadyRunningException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeployLoggerSecurityTest extends TestCase
{
    /** @var list<string> */
    private array $usernames = [];

    protected function tearDown(): void
    {
        gc_collect_cycles();
        foreach ($this->usernames as $username) {
            DeployLogger::deleteUserLogs($username);
        }
        parent::tearDown();
    }

    public function test_only_one_deploy_lock_can_be_held_per_user(): void
    {
        $username = $this->username();
        $first = DeployLogger::start($username);

        try {
            DeployLogger::start($username);
            $this->fail('A concurrent deploy should have been rejected.');
        } catch (DeployAlreadyRunningException $e) {
            $this->assertStringContainsString('already running', $e->getMessage());
        }

        $first->finish(DeployLogger::STATUS_SUCCESS);
        $second = DeployLogger::start($username);
        $second->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_resume_running_reuses_the_same_deploy_id(): void
    {
        $username = $this->username();
        $first = DeployLogger::start($username);
        $first->stage(DeployLogger::STAGE_PREPARING);
        $deployId = $first->getDeployId();
        unset($first);
        gc_collect_cycles();

        $resumed = DeployLogger::resumeRunningOrStart($username);
        $this->assertSame($deployId, $resumed->getDeployId());
        $this->assertTrue($resumed->isRunning());
        $resumed->finish(DeployLogger::STATUS_SUCCESS);

        $fresh = DeployLogger::resumeRunningOrStart($username);
        $this->assertNotSame($deployId, $fresh->getDeployId());
        $fresh->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_latest_error_is_redacted_before_persistence(): void
    {
        $logger = DeployLogger::start($this->username());
        $logger->finish(
            DeployLogger::STATUS_FAILED,
            'fatal: https://git:super-secret@git.example.test/org/repo.git Authorization: Bearer bearer-secret'
        );

        $latest = $logger->readLatest();
        $this->assertIsArray($latest);
        $this->assertStringNotContainsString('super-secret', (string) $latest['error']);
        $this->assertStringNotContainsString('bearer-secret', (string) $latest['error']);
        $this->assertStringContainsString('https://***@git.example.test', (string) $latest['error']);
    }

    #[DataProvider('connectionStrings')]
    public function test_credentials_in_any_connection_string_are_redacted(
        string $line,
        string $secret
    ): void {
        $logger = DeployLogger::start($this->username());
        $logger->finish(DeployLogger::STATUS_FAILED, $line);

        $error = (string) $logger->readLatest()['error'];
        $this->assertStringNotContainsString($secret, $error, $line);
        $this->assertStringContainsString('***@', $error, $line);
    }

    /**
     * The engine generates these itself, and applications print them verbatim
     * when a connection fails.
     *
     * @return array<string, array{string, string}>
     */
    public static function connectionStrings(): array
    {
        return [
            'postgres' => ['could not connect: postgres://app:pg-secret@db:5432/appdb', 'pg-secret'],
            'mysql' => ['mysql://app:my-secret@db:3306/appdb refused', 'my-secret'],
            'redis' => ['redis://:redis-secret@cache:6379/0 timed out', 'redis-secret'],
            'mongodb' => ['mongodb://root:mongo-secret@mongo:27017/appdb', 'mongo-secret'],
            'amqp' => ['amqp://guest:amqp-secret@rabbit:5672 unreachable', 'amqp-secret'],
        ];
    }

    public function test_a_url_without_credentials_is_left_readable(): void
    {
        $logger = DeployLogger::start($this->username());
        $logger->finish(DeployLogger::STATUS_FAILED, 'see https://docs.example.test/deploy for details');

        $this->assertStringContainsString(
            'https://docs.example.test/deploy',
            (string) $logger->readLatest()['error'],
            'masking must not eat ordinary links out of an error message'
        );
    }

    public function test_cancel_returns_pid_only_when_process_identity_still_matches(): void
    {
        $username = $this->username();
        $logger = DeployLogger::start($username);
        $logger->setPid(getmypid());

        $result = DeployLogger::requestCancel($username);
        $this->assertTrue($result['cancelled']);
        $this->assertSame(getmypid(), $result['pid']);
        $logger->finish(DeployLogger::STATUS_CANCELLED);
    }

    public function test_cancel_does_not_return_stale_reused_pid(): void
    {
        $username = $this->username();
        $logger = DeployLogger::start($username);
        $logger->setPid(getmypid());

        $latestPath = DeployLogger::baseDir() . '/' . $username . '/latest.json';
        $latest = json_decode((string) file_get_contents($latestPath), true);
        $latest['pid_start_time'] = '0';
        file_put_contents($latestPath, json_encode($latest));

        $result = DeployLogger::requestCancel($username);
        $this->assertTrue($result['cancelled']);
        $this->assertNull($result['pid']);
        $logger->finish(DeployLogger::STATUS_CANCELLED);
    }

    public function test_delete_user_logs_removes_hidden_files_and_the_directory(): void
    {
        $username = $this->username();
        $dir = DeployLogger::userDirFor($username);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->fail('Could not create deploy log dir');
        }
        file_put_contents($dir . '/.deploy.lock', '1');
        file_put_contents($dir . '/latest.json', '{}');
        file_put_contents($dir . '/20260819-old.log', '{}');

        DeployLogger::deleteUserLogs($username);

        $this->assertDirectoryDoesNotExist($dir);
        $this->usernames = array_values(array_diff($this->usernames, [$username]));
    }

    private function username(): string
    {
        $username = 'lock-' . bin2hex(random_bytes(6));
        $this->usernames[] = $username;

        return $username;
    }
}
