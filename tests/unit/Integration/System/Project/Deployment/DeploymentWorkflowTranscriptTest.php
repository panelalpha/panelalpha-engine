<?php

namespace Tests\Integration\System\Project\Deployment;

use App\Exceptions\DeployCancelledException;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Lib\Domains\PublicUrl;
use App\Lib\Findings\Finding;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project\Deployment\DeployMechanics;
use App\System\Project\Deployment\DeploymentWorkflow;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\AppLauncher;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use ArrayObject;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins, call by call, what each workflow path asks of its mechanics, the deploy log and Log,
 * and what it throws, for start results and failures the other workflow tests leave out.
 */
class DeploymentWorkflowTranscriptTest extends TestCase
{
    private const string HINT = 'Deploy failed with custom environment variables.';

    /** @var ArrayObject<int, string> */
    private ArrayObject $calls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = new ArrayObject;
        $calls = $this->calls;
        Log::swap(new class($calls)
        {
            public function __construct(private ArrayObject $calls) {}

            public function __call(string $name, array $arguments): void
            {
                $this->calls[] = 'Log::'.$name.'('.preg_replace('/state for alice: .*/s', 'state for alice: …', (string) ($arguments[0] ?? '')).')';
            }
        });
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('scenarios')]
    public function test_the_workflow_makes_the_same_calls_in_the_same_order(string $entry, array $setup, array $expected): void
    {
        $this->assertSame($expected, $this->transcript($entry, $setup));
    }

    /**
     * @return array<string, array{string, array<string, mixed>, array<string, mixed>}>
     */
    public static function scenarios(): array
    {
        $failed = ['exit_code' => 1, 'stdout' => 'pulling layer', 'stderr' => 'Error: manifest unknown'];
        $released = $failed + [AppLauncher::RELEASE_FAILED => true, AppLauncher::RAN_BEFORE_RELEASE => true];
        $releasedFirst = $failed + [AppLauncher::RELEASE_FAILED => true, AppLauncher::RAN_BEFORE_RELEASE => false];
        $releasedUnknown = $failed + [AppLauncher::RELEASE_FAILED => true];
        $kept = ZeroDowntimeRedeploy::previousKept('', 'health check failed', 1);
        $stdoutOnly = ['exit_code' => 3, 'stdout' => 'container exited with code 3', 'stderr' => ''];
        $cancel = new DeployCancelledException('Deployment cancelled by user');
        $boom = new \RuntimeException('prepare blew up');

        return [
            'run succeeds' => ['run', ['hasGit' => true], self::expected('run succeeds')],
            'run waits for files' => ['run', ['dindWithoutGit' => true], self::expected('run waits for files')],
            'run prepare fails with hint' => ['run', ['fail' => ['prepareHostingEnvironment' => $boom], 'envHint' => self::HINT, 'hook' => 'ok'], self::expected('run prepare fails with hint')],
            'run ingest fails without logger' => ['run', ['fail' => ['ingestApplicationSource' => new \RuntimeException('clone failed')], 'noLogger' => true], self::expected('run ingest fails without logger')],
            'run publish cancelled' => ['run', ['fail' => ['publishDomain' => $cancel], 'hook' => 'ok'], self::expected('run publish cancelled')],
            'run cancel checked after start' => ['run', ['hasGit' => true, 'cancelAt' => 1, 'hook' => 'throws'], self::expected('run cancel checked after start')],
            'run start fails, cleanup fails, hint' => ['run', ['hasGit' => true, 'start' => $failed, 'fail' => ['abortPartialDeploy' => new \RuntimeException('compose down failed')], 'envHint' => self::HINT, 'hook' => 'throws'], self::expected('run start fails, cleanup fails, hint')],
            'run start fails on stdout' => ['run', ['hasGit' => true, 'start' => $stdoutOnly], self::expected('run start fails on stdout')],
            'run start throws' => ['run', ['hasGit' => true, 'fail' => ['startApplication' => new \RuntimeException('docker daemon is not running')], 'envHint' => self::HINT], self::expected('run start throws')],
            'run start cancelled' => ['run', ['hasGit' => true, 'fail' => ['startApplication' => $cancel], 'hook' => 'ok'], self::expected('run start cancelled')],
            'run serving and public warnings with hint' => ['run', ['hasGit' => true, 'serving' => ['The application did not answer its health check.'], 'publicUrl' => ['Not reachable from the internet.'], 'envHint' => self::HINT], self::expected('run serving and public warnings with hint')],
            'run public warning only' => ['run', ['hasGit' => true, 'publicUrl' => ['Not reachable from the internet.'], 'envHint' => self::HINT], self::expected('run public warning only')],
            'run without git on another template' => ['run', ['template' => 'php'], self::expected('run without git on another template')],
            'run start fails without logger' => ['run', ['hasGit' => true, 'start' => $failed, 'noLogger' => true], self::expected('run start fails without logger')],
            'checkout succeeds reusing the running app' => ['checkout', ['running' => true], self::expected('checkout succeeds reusing the running app')],
            'checkout succeeds from a push' => ['checkout', ['source' => 'push', 'commit' => 'abc1234', 'branch' => 'main'], self::expected('checkout succeeds from a push')],
            'checkout release failed' => ['checkout', ['start' => $released], self::expected('checkout release failed')],
            'checkout first release failed' => ['checkout', ['start' => $releasedFirst], self::expected('checkout first release failed')],
            'checkout release failed, nothing known' => ['checkout', ['start' => $releasedUnknown], self::expected('checkout release failed, nothing known')],
            'checkout kept serving' => ['checkout', ['start' => $kept], self::expected('checkout kept serving')],
            'checkout start fails, cleanup fails' => ['checkout', ['start' => $failed, 'fail' => ['abortPartialDeploy' => new \RuntimeException('compose down failed')]], self::expected('checkout start fails, cleanup fails')],
            'checkout reprepare fails' => ['checkout', ['fail' => ['reprepareApplicationFromCheckout' => $boom]], self::expected('checkout reprepare fails')],
            'checkout reprepare cancelled' => ['checkout', ['fail' => ['reprepareApplicationFromCheckout' => $cancel]], self::expected('checkout reprepare cancelled')],
            'checkout cancel checked after start' => ['checkout', ['cancelAt' => 1], self::expected('checkout cancel checked after start')],
            'checkout serving warning with hint' => ['checkout', ['serving' => ['The application did not answer its health check.'], 'envHint' => self::HINT], self::expected('checkout serving warning with hint')],
            'checkout public warning only' => ['checkout', ['publicUrl' => ['Not reachable from the internet.'], 'envHint' => self::HINT], self::expected('checkout public warning only')],
            'source succeeds with an archive' => ['source', ['zip' => '/tmp/upload.zip'], self::expected('source succeeds with an archive')],
            'source continues a running deploy' => ['source', ['running' => true, 'loggerRunning' => true, 'latestStage' => DeployLogger::STAGE_PREPARING], self::expected('source continues a running deploy')],
            'source running log in another stage' => ['source', ['loggerRunning' => true, 'latestStage' => DeployLogger::STAGE_CLONING], self::expected('source running log in another stage')],
            'source release failed with hint' => ['source', ['start' => $released, 'envHint' => self::HINT], self::expected('source release failed with hint')],
            'source kept serving' => ['source', ['start' => $kept], self::expected('source kept serving')],
            'source start fails on stdout' => ['source', ['start' => $stdoutOnly], self::expected('source start fails on stdout')],
            'source ingest fails' => ['source', ['fail' => ['ingestForWipeRebuild' => $boom]], self::expected('source ingest fails')],
            'source ingest cancelled' => ['source', ['fail' => ['ingestForWipeRebuild' => $cancel]], self::expected('source ingest cancelled')],
            'source serving warning' => ['source', ['serving' => ['The application did not answer its health check.']], self::expected('source serving warning')],
            'archive succeeds without logger' => ['archive', ['noLogger' => true], self::expected('archive succeeds without logger')],
            'archive refused' => ['archive', ['fail' => ['ingestArchive' => new \InvalidArgumentException('Not a zip archive')]], self::expected('archive refused')],
            'archive start fails with hint' => ['archive', ['start' => $failed, 'envHint' => self::HINT], self::expected('archive start fails with hint')],
        ];
    }

    /**
     * @param  array<string, mixed>  $setup
     * @return array<string, mixed>
     */
    private function transcript(string $entry, array $setup): array
    {
        $model = $this->model($setup['template'] ?? 'dind');
        $mechanics = new TranscriptMechanics($model, $this->calls, $setup);
        $logger = ($setup['noLogger'] ?? false) ? null : $this->logger($setup);
        $workflow = new DeploymentWorkflow(null, $mechanics);
        $thrown = null;

        try {
            match ($entry) {
                'run' => $workflow->run($logger, $this->hook($setup['hook'] ?? null)),
                'checkout' => $workflow->rebuildFromCheckout($logger, $setup['source'] ?? 'git', $setup['commit'] ?? null, $setup['branch'] ?? null),
                'source' => $workflow->rebuildFromSource($logger, $setup['zip'] ?? null),
                'archive' => $workflow->deployFromArchive($logger, $setup['zip'] ?? '/tmp/upload.zip'),
            };
        } catch (\Throwable $e) {
            $thrown = [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'problems' => $e instanceof ProblemException ? $e->problems : null,
            ];
        }

        $details = $model->getDetails();

        return [
            'calls' => $this->calls->getArrayCopy(),
            'thrown' => $thrown,
            'details' => array_intersect_key($details, array_flip(['deployment_status', 'deployment_warnings', 'error', 'deployed_before'])),
        ];
    }

    private function hook(?string $kind): ?callable
    {
        if ($kind === null) {
            return null;
        }
        $calls = $this->calls;

        return function (DeployLogger $logger) use ($calls, $kind): void {
            $calls[] = 'hook()';
            if ($kind === 'throws') {
                throw new \RuntimeException('hook exploded');
            }
        };
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function logger(array $setup): DeployLogger
    {
        $calls = $this->calls;
        $state = new ArrayObject(['stage' => null, 'checks' => 0]);
        $logger = $this->createStub(DeployLogger::class);
        foreach (['info', 'dim', 'warn', 'recordFailureOutput'] as $method) {
            $logger->method($method)->willReturnCallback(function (?string $text) use ($calls, $method): void {
                $calls[] = "logger->{$method}({$text})";
            });
        }
        $logger->method('stage')->willReturnCallback(function (string $stage) use ($calls, $state): void {
            $state['stage'] = $stage;
            $calls[] = "logger->stage({$stage})";
        });
        $logger->method('waitForFiles')->willReturnCallback(function () use ($calls): void {
            $calls[] = 'logger->waitForFiles()';
        });
        $logger->method('finish')->willReturnCallback(function (string $status, ?string $error = null) use ($calls): void {
            $calls[] = "logger->finish({$status}, ".var_export($error, true).')';
        });
        $logger->method('finishFailed')->willReturnCallback(function (Finding $failure) use ($calls): void {
            $calls[] = "logger->finishFailed({$failure->code}, ".var_export($failure->message, true).')';
        });
        $logger->method('finishPartial')->willReturnCallback(function (array $warnings) use ($calls): void {
            $calls[] = 'logger->finishPartial('.var_export(Finding::sentence($warnings), true).')';
        });
        $logger->method('currentStage')->willReturnCallback(function () use ($calls, $state): ?string {
            $calls[] = 'logger->currentStage()';

            return $state['stage'];
        });
        $cancelAt = $setup['cancelAt'] ?? null;
        $logger->method('throwIfCancelled')->willReturnCallback(function () use ($calls, $state, $cancelAt): void {
            $calls[] = 'logger->throwIfCancelled()';
            $state['checks']++;
            if ($cancelAt === $state['checks']) {
                throw new DeployCancelledException('Deployment cancelled by user');
            }
        });
        $logger->method('readLatest')->willReturnCallback(function () use ($calls, $setup): array {
            $calls[] = 'logger->readLatest()';

            return ['stage' => $setup['latestStage'] ?? null];
        });
        $logger->method('isRunning')->willReturnCallback(function () use ($calls, $setup): bool {
            $calls[] = 'logger->isRunning()';

            return $setup['loggerRunning'] ?? false;
        });
        (new \ReflectionClass(DeployLogger::class))
            ->getProperty('lock')
            ->setValue($logger, new DeployLock(new DeployLogPaths('workflow-transcript-test')));

        return $logger;
    }

    private function model(string $template): ModelsUser
    {
        $model = new ModelsUser;
        $model->username = 'alice';
        $model->template = $template;
        $model->domain = 'alice.example.test';
        $model->setDetails(['UID' => 1000, 'GID' => 1000, 'template' => $template]);

        return $model;
    }

    /**
     * Recorded from the workflow as it stood before its long methods were split.
     *
     * @return array<string, mixed>
     */
    private static function expected(string $scenario): array
    {
        static $recorded = null;
        $recorded ??= json_decode(
            (string) file_get_contents(dirname(__DIR__, 4).'/fixtures/deployment-workflow-transcripts.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $recorded[$scenario];
    }
}

/**
 * @internal
 */
final class TranscriptMechanics implements DeployMechanics
{
    private DomainModel $domain;

    /**
     * @param  ArrayObject<int, string>  $calls
     * @param  array<string, mixed>  $setup
     */
    public function __construct(
        private ModelsUser $userModel,
        private ArrayObject $calls,
        private array $setup,
    ) {
        $this->domain = new DomainModel;
        $this->domain->domain = 'alice.example.test';
    }

    private function record(string $call, string $method): void
    {
        $this->calls[] = "mechanics->{$call}";
        $failure = $this->setup['fail'][$method] ?? null;
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
    }

    public function user(): ModelsUser
    {
        return $this->userModel;
    }

    public function requireMainDomain(): DomainModel
    {
        $this->record('requireMainDomain()', 'requireMainDomain');

        return $this->domain;
    }

    public function hasGitProject(): bool
    {
        $this->record('hasGitProject()', 'hasGitProject');

        return $this->setup['hasGit'] ?? false;
    }

    public function isDindWithoutGit(): bool
    {
        $this->record('isDindWithoutGit()', 'isDindWithoutGit');

        return $this->setup['dindWithoutGit'] ?? false;
    }

    public function prepareHostingEnvironment(): void
    {
        $this->record('prepareHostingEnvironment()', 'prepareHostingEnvironment');
    }

    public function removedTheAccount(): bool
    {
        $this->record('removedTheAccount()', 'removedTheAccount');

        return false;
    }

    public function ingestApplicationSource(): void
    {
        $this->record('ingestApplicationSource()', 'ingestApplicationSource');
    }

    public function isApplicationEnvironmentRunning(): bool
    {
        $this->record('isApplicationEnvironmentRunning()', 'isApplicationEnvironmentRunning');

        return $this->setup['running'] ?? false;
    }

    public function recordRunningVersion(): void
    {
        $this->record('recordRunningVersion()', 'recordRunningVersion');
    }

    public function repairAccountMapping(): void
    {
        $this->record('repairAccountMapping()', 'repairAccountMapping');
    }

    public function syncHostingForCheckoutRebuild(bool $reuseRunning): void
    {
        $this->record('syncHostingForCheckoutRebuild('.var_export($reuseRunning, true).')', 'syncHostingForCheckoutRebuild');
    }

    public function syncHostingForSourceRebuild(?string $zipPath): void
    {
        $this->record('syncHostingForSourceRebuild('.var_export($zipPath, true).')', 'syncHostingForSourceRebuild');
    }

    public function ingestForWipeRebuild(?string $zipPath): void
    {
        $this->record('ingestForWipeRebuild('.var_export($zipPath, true).')', 'ingestForWipeRebuild');
    }

    public function reprepareApplicationFromCheckout(): void
    {
        $this->record('reprepareApplicationFromCheckout()', 'reprepareApplicationFromCheckout');
    }

    public function ingestArchive(string $zipPath): void
    {
        $this->record("ingestArchive({$zipPath})", 'ingestArchive');
    }

    public function publishDomain(DomainModel $domain): void
    {
        $this->record("publishDomain({$domain->domain})", 'publishDomain');
    }

    public function startApplication(): array
    {
        $this->record('startApplication()', 'startApplication');

        return $this->setup['start'] ?? ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
    }

    public function abortPartialDeploy(bool $removeVolumes = false): void
    {
        $this->record('abortPartialDeploy('.var_export($removeVolumes, true).')', 'abortPartialDeploy');
    }

    public function settleRedeploy(bool $succeeded): void
    {
        $this->record('settleRedeploy('.var_export($succeeded, true).')', 'settleRedeploy');
    }

    public function servingWarnings(): array
    {
        $this->record('servingWarnings()', 'servingWarnings');

        return array_map(static fn (string $message): Finding => new Finding(AppHealth::CODE_NOT_ANSWERING, $message), $this->setup['serving'] ?? []);
    }

    public function publicUrlWarnings(DomainModel $domain): array
    {
        $this->record("publicUrlWarnings({$domain->domain})", 'publicUrlWarnings');

        return array_map(static fn (string $message): Finding => new Finding(PublicUrl::UNRESOLVABLE, $message), $this->setup['publicUrl'] ?? []);
    }

    public function customEnvFailureHint(): ?string
    {
        $this->record('customEnvFailureHint()', 'customEnvFailureHint');

        return $this->setup['envHint'] ?? null;
    }

    public function persistPartialSuccess(array $warnings): void
    {
        $this->record('persistPartialSuccess('.Finding::sentence($warnings).')', 'persistPartialSuccess');
        $this->userModel->setDetails(['deployment_warnings' => Finding::storable($warnings), 'deployment_status' => 'partial']);
    }

    public function persistSuccess(): void
    {
        $this->record('persistSuccess()', 'persistSuccess');
        $this->userModel->setDetails(['deployment_status' => 'success', 'deployment_warnings' => []]);
    }
}
