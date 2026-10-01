<?php

namespace App\Console\Commands\Users;

use App\Http\Requests\SshCommandRunRequest;
use App\Lib\Project\ProjectShell;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

/** One shell command in a project's container, run through the shared ProjectShell. */
class ProjectSshCommand extends Command
{
    protected $signature = 'project:ssh
                            {project : Project username}
                            {cmd : Shell command line to run, quoted}
                            {--cwd= : Directory to run in; defaults to the account home directory}
                            {--timeout=300 : Seconds before the command is killed}
                            {--json : Print the raw JSON result instead of the output streams}';

    protected $description = "Run a shell command inside a project's container, as the project user";

    /**
     * Keep the two streams apart, so `project:ssh … 2>/dev/null` behaves the way
     * it would for the command being run.
     *
     * OutputStyle's own getErrorOutput() is protected — calling it from here is
     * a fatal Error, and one that only shows when a command actually writes to
     * stderr. The underlying output is public, and falls back to stdout when
     * there is no separate error stream.
     */
    public function writeStreams(string $stdout, string $stderr): void
    {
        if ($stdout !== '') {
            $this->output->write($stdout);
        }
        if ($stderr === '') {
            return;
        }

        $out = $this->output->getOutput();
        $err = $out instanceof ConsoleOutputInterface ? $out->getErrorOutput() : $out;
        $err->write($stderr);
    }

    public function handle(): int
    {
        $project = (string)$this->argument('project');
        // Not `command`: Symfony Console already defines an argument of that
        // name on every command - the command's own name.
        $command = (string)$this->argument('cmd');
        $cwd = $this->option('cwd');
        $timeout = (int)$this->option('timeout');

        if ($timeout < 1 || $timeout > SshCommandRunRequest::MAX_TIMEOUT) {
            $this->error('--timeout must be between 1 and ' . SshCommandRunRequest::MAX_TIMEOUT . ' seconds');

            return 1;
        }

        $params = ['command' => $command, 'timeout' => $timeout];
        if (is_string($cwd) && $cwd !== '') {
            $params['cwd'] = $cwd;
        }

        Validator::make($params, (new SshCommandRunRequest())->rules())->validate();
        $user = User::findByUsernameOrFail($project);
        $result = ProjectShell::run($user, $command, $params['cwd'] ?? null, $timeout);

        if ($this->option('json')) {
            $this->output->writeln(json_encode($result, JSON_THROW_ON_ERROR));

            return 0;
        }

        $this->writeStreams($result['stdout'], $result['stderr']);

        // Hand back the command's own exit code, so `pae-artisan project:ssh …`
        // can be tested in a shell the same way the command would be.
        return $result['exit_code'];
    }
}
