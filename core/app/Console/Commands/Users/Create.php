<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\DispatchesApiRoute;
use App\Lib\Deploy\DeployLog\DeployLineFormat;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Domains\PublicUrl;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The command behind POST /users, and what the one-line installer
 * (`installer.sh --repo …`) calls once the engine is up. Every detail is
 * optional: the account name, the domain and the template are generated from
 * the repository, so `pae project:create --repo owner/repo` is a whole install.
 *
 * The synchronous route on purpose -- the deploy runs in this process, so the
 * command exits when the application is actually live and its exit code says
 * whether it is. The async POST /projects would hand back a task id and leave
 * the caller polling.
 */
class Create extends Command
{
    use DispatchesApiRoute;

    /** The plural spellings the other project commands answer to. */
    protected $aliases = ['projects:create', 'users:create'];

    protected $signature = 'project:create
                            {--repo= : Git repository to deploy: a clone URL, host/owner/repo, or owner/repo on the default forge}
                            {--branch= : Branch, tag or commit to deploy; the repository default when omitted}
                            {--git-token= : HTTPS access token for a private repository}
                            {--project= : Account username; generated from the repository when omitted}
                            {--domain= : Main domain; one is allocated when omitted}
                            {--email= : Contact email for the project}
                            {--template= : Project template; dind when a repository is given}
                            {--recipe= : Deploy with this recipe instead of the detected one}
                            {--env-var=* : KEY=VALUE environment override, repeatable}
                            {--quiet-deploy : Do not print the deploy log while it runs}
                            {--json : Print the created project as JSON instead of a summary}';

    protected $description = 'Create a project and deploy a repository into it; details not given are generated';

    public function handle(): int
    {
        $params = [];
        foreach ([
            'username' => 'project',
            'domain' => 'domain',
            'email' => 'email',
            'template' => 'template',
            'recipe' => 'recipe',
            'git_branch' => 'branch',
            'git_token' => 'git-token',
        ] as $field => $option) {
            $value = $this->option($option);
            if (is_string($value) && trim($value) !== '') {
                $params[$field] = trim($value);
            }
        }

        $repo = $this->option('repo');
        if (is_string($repo) && trim($repo) !== '') {
            $params['git_repo'] = GitRepoInput::expandShorthand($repo, self::shorthandHost());
        }

        $envVars = $this->envVars();
        if ($envVars === null) {
            return 1;
        }
        if ($envVars !== []) {
            $params['env_vars'] = $envVars;
        }

        if (!$this->option('json')) {
            $this->info(isset($params['git_repo'])
                ? "Creating a project for {$params['git_repo']} — this takes a few minutes."
                : 'Creating a project — this takes a few minutes.');
        }

        // The deploy runs inside this process, and DeployLogStream is
        // process-local, so the command can subscribe to the same lines the
        // NDJSON API stream carries and print them as they happen -- no
        // polling, no second process, and nothing to miss between reads.
        $this->followDeployLog();

        try {
            $response = $this->dispatchApiRoute('POST', '/users', $params);
        } finally {
            DeployLogger::stopStreaming();
        }
        if ($response->getStatusCode() >= 400) {
            $this->error($this->errorMessage($response));
            // A failed deploy is rolled back but its log is kept, and the
            // sentence above is a summary of it.
            if (isset($params['git_repo'])) {
                $this->line('Deploy log: pae project:deploy:list');
            }
            return 1;
        }

        return $this->report($response);
    }

    /**
     * Print each deploy log line as the pipeline writes it. `--verbose` adds
     * the dim subprocess output, which is the build's own stdout and runs to
     * thousands of lines.
     *
     * Under `--json` the log goes to stderr, so a caller that captures stdout
     * -- installer.sh does -- still gets nothing but the JSON, while whoever
     * is watching the terminal sees the deploy happen.
     */
    private function followDeployLog(): void
    {
        if ($this->option('quiet-deploy')) {
            return;
        }

        $out = $this->deployOutput();
        $verbose = $this->output->isVerbose();
        // The first line is written before any frame reaches us, so the clock
        // starts at the first thing we see rather than at the deploy's own
        // start -- close enough, and it never shows a negative elapsed time.
        $startedAt = 0;

        DeployLogger::streamTo(function (array $frame) use ($out, &$startedAt, $verbose): void {
            // `stage` frames carry no message of their own and no timestamp;
            // the heading comes from the log line that accompanies them, so
            // this reads exactly like the same deploy read back from disk.
            if (($frame['type'] ?? null) !== 'line') {
                return;
            }
            if ($startedAt === 0) {
                $startedAt = (int) ($frame['ts'] ?? time());
            }

            $line = DeployLineFormat::line($frame, $startedAt, $verbose);
            if ($line !== null) {
                $out->writeln($line);
            }
        });
    }

    /** stderr under --json, so stdout carries the JSON and nothing else. */
    private function deployOutput(): OutputInterface
    {
        $out = $this->output->getOutput();

        return $this->option('json') && $out instanceof ConsoleOutputInterface
            ? $out->getErrorOutput()
            : $out;
    }

    /**
     * The forge a bare `owner/repo` is taken to mean. GitHub unless an
     * operator says otherwise: `pae settings:set default_git_host gitlab.com`.
     * A full URL never consults this.
     */
    private static function shorthandHost(): ?string
    {
        $host = Setting::get('default_git_host');

        return is_string($host) && trim($host) !== '' ? $host : null;
    }

    /**
     * --env-var KEY=VALUE, repeatable; `--env` is Laravel's own option and
     * cannot be taken. Null when one is malformed: a mistyped override is
     * worth an error rather than a project deployed without the variable it
     * was supposed to carry.
     *
     * @return ?array<string, string>
     */
    private function envVars(): ?array
    {
        /** @var array<int, string> $pairs */
        $pairs = (array) $this->option('env-var');
        $vars = [];
        foreach ($pairs as $pair) {
            if (!str_contains($pair, '=')) {
                $this->error("--env-var expects KEY=VALUE, got: {$pair}");
                return null;
            }
            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);
            if ($key === '') {
                $this->error("--env-var expects KEY=VALUE, got: {$pair}");
                return null;
            }
            $vars[$key] = $value;
        }

        return $vars;
    }

    /** The created project, as JSON or as the two lines an installer prints. */
    private function report(Response $response): int
    {
        $body = $response->getContent();
        $body = $body === false ? '' : $body;

        if ($this->option('json')) {
            $this->output->writeln($body);
            return 0;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        $data = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $username = is_string($data['username'] ?? null) ? $data['username'] : '';
        $domain = is_string($data['domain'] ?? null) ? $data['domain'] : '';

        if ($username === '') {
            $this->error('The project was created but the response carried no username.');
            return 1;
        }

        $this->info("Project {$username} created.");
        if ($domain !== '') {
            $this->info('Live at ' . $this->url($domain));
        }

        // Said here rather than left to be discovered in a browser: a host with
        // no license or no public address lands the project on a name that
        // resolves nowhere, and the deploy itself succeeded all the same.
        $user = User::findByUsername($username);
        if ($user !== null && $domain !== '') {
            foreach (PublicUrl::warnings($domain, $user->getDetails()) as $warning) {
                $this->warn($warning);
            }
        }

        return 0;
    }

    /** The scheme the project's own domain is served on. */
    private function url(string $domain): string
    {
        $model = Domain::findByName($domain);
        $details = $model !== null && is_array($model->details) ? $model->details : [];

        return (empty($details['ssl_disabled']) ? 'https://' : 'http://') . $domain;
    }
}
