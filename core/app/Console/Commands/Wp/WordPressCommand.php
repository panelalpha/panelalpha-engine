<?php

namespace App\Console\Commands\Wp;

use App\Models\User;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Console\Command;

/**
 * One call on a project's WordPress install, printed as JSON. A failure
 * prints its message and exits 1.
 */
abstract class WordPressCommand extends Command
{
    use SelectsWordPressInstall;

    abstract protected function perform(WordPress $wordpress): mixed;

    public function handle(): int
    {
        $user = User::findByUsernameOrFail((string) $this->argument('username'));
        $wordpress = WordPress::for(
            $user,
            $this->optionalOption('path'),
            service: $this->optionalOption('service'),
            skipPlugins: (bool) $this->option('skip-plugins'),
        );

        try {
            $data = $this->perform($wordpress);
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    /** A value option, or null when it is missing or empty. */
    protected function optionalOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    protected function listArgument(string $name): array
    {
        return array_values(array_map('strval', (array) $this->argument($name)));
    }
}
