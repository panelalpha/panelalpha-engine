<?php

namespace App\Console\Commands\Git;

use App\Lib\Vault\RequestVault;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The git:* commands run the same GitActions as the /git endpoints and print
 * the checkout's data as `{"data": ...}` JSON.
 */
trait PrintsGitJson
{
    protected function configure(): void
    {
        parent::configure();

        if (! $this->getDefinition()->hasOption('raw')) {
            $this->getDefinition()->addOption(
                new InputOption('raw', null, InputOption::VALUE_NONE, 'Print raw JSON (for piping to jq)')
            );
        }
    }

    protected function resolvePath(): ?string
    {
        $path = $this->option('path');
        if (! is_string($path)) {
            return null;
        }
        $path = trim($path);

        return $path === '' ? null : $path;
    }

    /**
     * Validate `$input` with the endpoint's FormRequest rules, find the
     * project, run `$action` and print what it returns. A null from the
     * action prints nothing.
     *
     * @param  class-string<FormRequest>  $rules
     * @param  array<string, mixed>  $input
     * @param  callable(User, array<string, mixed>): mixed  $action
     *
     * @throws \JsonException
     */
    protected function runGit(string $rules, array $input, callable $action): int
    {
        $params = $this->validated($rules, $input);
        $user = User::findByUsernameOrFail((string) $this->argument('username'));
        $data = $action($user, $params);

        if ($data === null) {
            return self::SUCCESS;
        }

        $content = json_encode(['data' => $data], JSON_THROW_ON_ERROR);
        if ($this->option('raw')) {
            $this->output->writeln($content, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        $this->line((string) json_encode($decoded, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * @param  class-string<FormRequest>  $rules
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(string $rules, array $input): array
    {
        $request = new $rules;
        $validator = Validator::make($this->withVaultSecrets($input), $request->rules());
        if (method_exists($request, 'withValidator')) {
            $request->withValidator($validator);
        }

        return $validator->validate();
    }

    /**
     * The input with a `vault:<key>` token read in its place, as the HTTP
     * request reads it; an unusable reference is a validation error. A project
     * that does not exist is not found before the reference is read.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function withVaultSecrets(array $input): array
    {
        if (! RequestVault::isReference($input['token'] ?? null)) {
            return $input;
        }

        $project = User::findByUsernameOrFail((string) $this->argument('username'));
        $input['token'] = RequestVault::resolve('token', $input['token'], $project->username);

        return $input;
    }
}
