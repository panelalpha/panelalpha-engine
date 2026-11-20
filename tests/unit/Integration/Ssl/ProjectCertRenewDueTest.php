<?php

namespace Tests\Integration\Ssl;

use App\Console\Commands\Ssl\ProjectCertRenew;
use App\Lib\Ssl\IssuableDomain;
use App\Lib\Ssl\Issuer;
use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\Domain as ProjectDomain;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** `ssl:project-cert:renew`: which certificates are due, who renews each one, and how one failure is kept from the next domain. */
class ProjectCertRenewDueTest extends TestCase
{
    use InMemoryDatabase;

    private const DAY = 86400;

    /** @var array<string, ?array<string, mixed>> domain => certificate on disk, null for none */
    private array $certificates = [];

    /** @var list<string> */
    private array $engineCovers = [];

    /** @var list<string> */
    private array $ineligible = [];

    /** @var list<string> */
    private array $failing = [];

    /** @var list<string> */
    private array $calls = [];

    private string $printed = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeUser('alice');
    }

    private function domain(string $name, ?array $certificate): void
    {
        $user = User::findByUsername('alice');
        Domain::query()->create(['user_id' => $user->id, 'domain' => $name, 'type' => 'addon', 'details' => ['document_root' => "/{$name}/public_html"]]);
        $this->certificates[$name] = $certificate;
    }

    private static function issued(string $name, int $daysLeft): array
    {
        return [
            'common_name' => $name,
            'issuer_common_name' => 'R11',
            'domains' => [$name],
            'not_before' => time() - 60 * self::DAY,
            'not_after' => time() + $daysLeft * self::DAY + 3600,
        ];
    }

    private static function selfSigned(string $name): array
    {
        return ['common_name' => $name, 'issuer_common_name' => $name, 'domains' => [$name], 'not_before' => time() - self::DAY, 'not_after' => time() + 3650 * self::DAY];
    }

    public function record(string $call): void
    {
        $this->calls[] = $call;
        foreach ($this->failing as $name) {
            if (str_ends_with($call, $name)) {
                throw new RuntimeException('authority unavailable');
            }
        }
    }

    /** @return ?array<string, mixed> */
    public function certificateOf(string $name): ?array
    {
        return $this->certificates[$name] ?? null;
    }

    public function engineCoversName(string $name): bool
    {
        return in_array($name, $this->engineCovers, true);
    }

    public function issuerFor(): Issuer
    {
        $test = $this;

        return new class($test) implements Issuer
        {
            public function __construct(private readonly ProjectCertRenewDueTest $test) {}

            public function id(): string
            {
                return 'test-authority';
            }

            public function ineligibleReason(string $domain): ?string
            {
                return $this->test->ineligibleName($domain) ? 'not pointed here' : null;
            }

            public function issue(IssuableDomain $domain): void
            {
                $this->test->record('issue '.$domain->model()->domain);
            }
        };
    }

    public function ineligibleName(string $name): bool
    {
        return in_array($name, $this->ineligible, true);
    }

    public function hostOf(Domain $model): ProjectDomain
    {
        $test = $this;

        return new class(new Project(new RecordingSystem(), $model->user), $model, $test) extends ProjectDomain
        {
            public function __construct(Project $project, Domain $model, private readonly ProjectCertRenewDueTest $test)
            {
                parent::__construct($project, $model);
            }

            public function hasSslCertificate(): bool
            {
                return $this->test->certificateOf($this->model()->domain) !== null;
            }

            public function getSslCertificateInfo(): array
            {
                return (array) $this->test->certificateOf($this->model()->domain);
            }

            public function generateCertificate(): void
            {
                $this->test->record('engine '.$this->model()->domain);
            }

            public function rebuild(): void
            {
                $this->test->record('rebuild '.$this->model()->domain);
            }
        };
    }

    private function renew(array $options = []): int
    {
        $test = $this;
        $command = new class($test) extends ProjectCertRenew
        {
            public function __construct(private readonly ProjectCertRenewDueTest $test)
            {
                parent::__construct();
            }

            protected function issuer(bool $staging): Issuer
            {
                return $this->test->issuerFor();
            }

            protected function engineCovers(string $name): bool
            {
                return $this->test->engineCoversName($name);
            }

            protected function projectDomain(Domain $model): ProjectDomain
            {
                return $this->test->hostOf($model);
            }
        };
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $code = $command->run(new ArrayInput($options), $output);
        $this->printed = $output->fetch();

        return $code;
    }

    public function test_only_issued_certificates_running_out_are_renewed_by_the_authority(): void
    {
        $this->domain('due.example.test', self::issued('due.example.test', 10));
        $this->domain('fresh.example.test', self::issued('fresh.example.test', 80));
        $this->domain('expired.example.test', self::issued('expired.example.test', -3));
        $this->domain('own.example.test', self::selfSigned('own.example.test'));
        $this->domain('none.example.test', null);

        $this->assertSame(0, $this->renew());

        $this->assertSame([
            'issue due.example.test', 'rebuild due.example.test',
            'issue expired.example.test', 'rebuild expired.example.test',
        ], $this->calls);
        $this->assertStringContainsString('renewed due.example.test', $this->printed);
        $this->assertStringContainsString('2 renewed, 0 failed', $this->printed);
    }

    public function test_a_name_the_issuer_refuses_is_skipped_unless_the_engine_certificate_covers_it(): void
    {
        $this->domain('elsewhere.example.test', self::issued('elsewhere.example.test', 5));
        $this->domain('engine.example.test', self::issued('engine.example.test', 5));
        $this->ineligible = ['elsewhere.example.test', 'engine.example.test'];
        $this->engineCovers = ['engine.example.test'];

        $this->assertSame(0, $this->renew());

        $this->assertSame(['engine engine.example.test', 'rebuild engine.example.test'], $this->calls);
        $this->assertStringContainsString('1 renewed, 0 failed', $this->printed);
    }

    public function test_the_threshold_force_and_domain_options_choose_what_is_due(): void
    {
        $this->domain('one.example.test', self::issued('one.example.test', 40));
        $this->domain('two.example.test', self::issued('two.example.test', 70));
        $this->domain('own.example.test', self::selfSigned('own.example.test'));

        $this->renew(['--days' => '50']);
        $this->assertSame(['issue one.example.test', 'rebuild one.example.test'], $this->calls);

        $this->calls = [];
        $this->renew(['--force' => true]);
        $this->assertSame(['issue one.example.test', 'rebuild one.example.test', 'issue two.example.test', 'rebuild two.example.test'], $this->calls);

        $this->calls = [];
        $this->renew(['--force' => true, '--domain' => ' TWO.example.test ']);
        $this->assertSame(['issue two.example.test', 'rebuild two.example.test'], $this->calls);
    }

    public function test_a_dry_run_lists_what_is_due_and_requests_nothing(): void
    {
        $this->domain('due.example.test', self::issued('due.example.test', 10));

        $this->assertSame(0, $this->renew(['--dry-run' => true]));

        $this->assertSame([], $this->calls);
        $this->assertStringContainsString('would renew due.example.test (trusted, 10 days left)', $this->printed);
        $this->assertStringContainsString('1 renewed, 0 failed', $this->printed);
    }

    public function test_one_failed_renewal_does_not_stop_the_next_and_fails_the_run(): void
    {
        $this->domain('broken.example.test', self::issued('broken.example.test', 2));
        $this->domain('fine.example.test', self::issued('fine.example.test', 2));
        $this->failing = ['broken.example.test'];

        $this->assertSame(1, $this->renew());

        $this->assertSame(['issue broken.example.test', 'issue fine.example.test', 'rebuild fine.example.test'], $this->calls);
        $this->assertStringContainsString('could not renew broken.example.test: authority unavailable', $this->printed);
        $this->assertStringContainsString('1 renewed, 1 failed', $this->printed);
    }
}
