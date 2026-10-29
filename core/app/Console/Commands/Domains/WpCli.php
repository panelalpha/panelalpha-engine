<?php

namespace App\Console\Commands\Domains;

use App\Models\Domain;
use App\Models\User;
use App\System\Project\PhpHosting\WordPress\WpCli as WordPressWpCli;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class WpCli extends Command
{
    protected $signature = 'domain:wp-cli {domain} {arg*}';

    protected $description = 'Deprecated: prefer wordpress:command {username}. Runs WP-CLI as the domain\'s project user, using that domain\'s document root as --path if not provided.';

    public function handle(): int
    {
        $domainName = $this->argument('domain');
        assert(is_string($domainName));
        $domain = Domain::findByName($domainName);
        if (! $domain) {
            $this->error("Domain `{$domainName}` not found in database.");

            return 1;
        }

        $user = $domain->user;
        if (! $user) {
            $this->error("User #{$domain->user_id} of domain `{$domainName}` not found in database.");

            return 1;
        }

        $args = $this->argument('arg');
        assert(is_array($args));
        /** @var list<string> $wpCliArgs */
        $wpCliArgs = [];
        $hasPathInArgs = false;
        foreach ($args as $arg) {
            assert(is_string($arg));
            if (Str::startsWith($arg, '--path=')) {
                $hasPathInArgs = true;
            }
            $wpCliArgs[] = $arg;
        }

        $result = WordPressWpCli::for($user, $this->documentRootPath($user, $domain, $hasPathInArgs))->run($wpCliArgs);

        $this->line('stdout:');
        $this->info(trim($result['stdout']));
        $this->line('stderr:');
        $this->warn(trim($result['stderr']));
        $this->line('exit_code: '.$result['exit_code']);

        return 0;
    }

    /** A container project's install is found in its containers, not under a domain's document root. */
    private function documentRootPath(User $user, Domain $domain, bool $hasPathInArgs): ?string
    {
        $cli = WordPressWpCli::for($user);

        return $hasPathInArgs || $cli->runsInContainer() ? null : $cli->resolveDomainPath($domain);
    }
}
