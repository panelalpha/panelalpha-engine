<?php

namespace App\Console\Commands\System;

use App\System;
use App\Lib\Helper;
use App\Models\Admin;
use App\Models\Domain;
use App\Models\MysqlDatabase;
use App\Models\MysqlUser;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class CreateExampleDomain extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['system:create-example-domain'];

    protected $signature = 'system:example:create {--git-repo= : Git repository URL or owner/repo to deploy instead of WordPress}';

    protected $description = 'Create example domain if not created before.';

    private function normalizeGitRepo(string $repo): string
    {
        // If it's already a full URL, return as-is
        if (filter_var($repo, FILTER_VALIDATE_URL)) {
            return $repo;
        }

        // If it starts with github.com/, add https://
        if (str_starts_with($repo, 'github.com/')) {
            return 'https://' . $repo;
        }

        // If it's in owner/repo format, assume GitHub
        if (preg_match('/^[^\/]+\/[^\/]+$/', $repo)) {
            return 'https://github.com/' . $repo;
        }

        // Otherwise return as-is (will fail validation)
        return $repo;
    }

    public function handle(): int
    {
        $alreadyCreated = !empty(Setting::get('example-domain-created'));
        if ($alreadyCreated) {
            $this->info('Example domain has been created before.');
            return 0;
        }

        // $username = $this->getUsername();
        // $domainName = $this->getDomainName();
        
        
        // $domainRedirectUrl = null;

        // /** @var mixed */
        // $aaPort = config('env.APP_LITE_AA_PORT');
        // if (!is_numeric($aaPort)) {
        //     $aaPort = "8443";
        // }
        // $adminUrl = 'https://' . (string)Setting::get('default_ipv4') . ':' . $aaPort;

        // /** @var mixed */
        // $aaHostLocal = config('env.APP_LITE_PROXY_AA_HOST');
        // if (!is_string($aaHostLocal)) {
        //     $aaHostLocal = 'panel.app-lite.palocal';
        // }
        // /** @var mixed */
        // $aaPortLocal = config('env.APP_LITE_PROXY_AA_PORT');
        // if (!is_numeric($aaPortLocal)) {
        //     $aaPortLocal = '81';
        // }
        // $adminHomeUrl = 'http://' . $aaHostLocal . ':' . $aaPortLocal . '/api/admin/home';

        // try {
        //     $response = Http::timeout(3)->get($adminHomeUrl);
        //     if (!empty($response->json('app.configuration_required'))) {
        //         $domainRedirectUrl = $adminUrl;
        //     }
        // } catch (\Exception $e) {
        //     Log::debug("create-example-domain: exception occured during app configuration check", [
        //         'check_url' => $adminHomeUrl,
        //         'exception_message' => $e->getMessage(),
        //     ]);
        // }

        $params = [
            // 'username' => $username,
            // 'domain' => $domainName,
            // 'domain_redirect_url' => $domainRedirectUrl,
        ];

        $gitRepo = $this->option('git-repo');
        if (is_string($gitRepo)) {
            $params['git_repo'] = $this->normalizeGitRepo($gitRepo);
            $params['username'] = Helper::generateUsername($gitRepo);
        }
        if (!Setting::get('default_wildcard_domain')) {
            $defaultIpv4 = (string)Setting::get('default_ipv4');
            if (filter_var($defaultIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                Setting::set('default_wildcard_domain', Str::replace('.', '-', $defaultIpv4) . '.sslip.io');
            }
        }
        if (empty($params['username'])) {
            $params['username'] = $this->getUsername('wordpress');
        }
        $params['domain'] = Helper::generateDomainName($params['username']);
        Setting::set('vhost-default-ip-domain', $params['domain']);

        Log::debug("create-example-domain: creating user with params", [
            'params' => $params,
        ]);
        $response = $this->callApi('POST', '/projects', $params);

        if ($response->getStatusCode() >= 400) {
            /** @var mixed $data */
            $data = $response->getData(true);
            $error = 'unknown response';
            if (is_array($data) && !empty($data['message']) && is_string($data['message'])) {
                $error = $data['message'];
            } else if ($content = $response->getContent()) {
                $error = $content;
            }
            $this->error('ERROR during API call POST /users: ' . $error);
            return 1;
        }
        Setting::set('example-domain-created', '1');

        $user = User::findByUsername($params['username']);
        if (!$user) {
            $this->error('Something went wrong during user creation.');
            return 1;
        }
        $domain = Domain::findByName($params['domain']);
        if (!$domain) {
            $this->error('Something went wrong during domain creation.');
            return 1;
        }

        // Flag this domain as an example domain for proper identification during deletion
        $domain->setAsExampleDomain();
        $domain->save();

        if ($user->getGitRepo()) {
            $url = 'https://' . (string)Setting::get('default_ipv4');
            $this->info("Example domain with Git repository created: {$url}");
            return 0;
        }

        return $this->handleWordpressInstallation($user, $domain);
    }

    private function handleWordpressInstallation(User $user, Domain $domain): int
    {
        // Only install WordPress if no git repo is specified
        $path = $user->getHomeDir() . $domain->getDocumentRoot();
        $result = $user->project()->runWpCli([
            'core',
            'download',
            '--path=' . $path,
        ]);
        if ($result['exit_code'] !== 0) {
            $this->error('Error during wp-cli core download: ' . ($result['stderr'] ?: $result['stdout']) . " (exit code {$result['exit_code']})");
            return 1;
        }

        $mysql = (new System())->mysql();
        $dbname = $user->getMysqlPrefix() . 'example';
        $mysql->databases()->createDatabase($dbname);
        MysqlDatabase::create([
            'user_id' => $user->id,
            'database' => $dbname,
        ]);
        $dbuser = $user->getMysqlPrefix() . 'example';
        $dbpass = Str::random(16);
        $mysql->users()->createUser($dbuser, $dbpass);
        MysqlUser::create([
            'user_id' => $user->id,
            'user' => $dbuser,
        ]);
        $mysql->privileges()->updatePrivileges($dbuser, $dbname, 'ALL PRIVILEGES');

        /** @var mixed */
        $dbhost = config('env.USERS_DB_HOST');
        if (!is_string($dbhost)) {
            $dbhost = 'database-users.shared-hosting.palocal';
        }
        $result = $user->project()->runWpCli([
            'config',
            'create',
            '--path=' . $path,
            '--dbname=' . $dbname,
            '--dbuser=' . $dbuser,
            '--dbpass=' . $dbpass,
            '--dbhost=' . $dbhost,
        ]);
        if ($result['exit_code'] !== 0) {
            $this->error('Error during wp-cli config create: ' . ($result['stderr'] ?: $result['stdout']) . " (exit code {$result['exit_code']})");
            return 1;
        }

        $url = 'https://' . (string)Setting::get('default_ipv4');
        $result = $user->project()->runWpCli([
            'core',
            'install',
            '--path=' . $path,
            '--url=' . $url,
            '--title=Example Website',
            '--admin_user=admin',
            '--admin_password=' . Str::random(16),
            '--admin_email=' . $this->getEmail(),
            '--skip-email',
        ]);
        if ($result['exit_code'] !== 0) {
            $this->error('Error during wp-cli core install: ' . ($result['stderr'] ?: $result['stdout']) . " (exit code {$result['exit_code']})");
            return 1;
        }

        $themeName = 'twentytwentyfour';
        $result = $user->project()->runWpCli([
            'theme',
            'install',
            $themeName,
            '--activate',
            '--path=' . $path,
        ]);
        if ($result['exit_code'] !== 0) {
            Log::warning("Could not install theme '{$themeName}' on example domain", $result);
        }

        $this->info("Example domain with WordPress created: {$url}");
        return 0;
    }

    // private function getDomainName(): string
    // {
    //     $domainName = "example.local";
    //     $limit = 10;
    //     do {
    //         if (!Domain::domainOrAliasExists($domainName)) {
    //             return $domainName;
    //         }
    //         $domainName = "example" . rand(1000, 9999) . ".local";
    //     } while ($limit--);
    //     throw new \Exception('Cannot get available domain name');
    // }

    private function getEmail(): string
    {
        $email = (string)Setting::get('email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
        return 'user@localhost.localdomain';
    }

    private function getUsername(string $base = 'user'): string
    {
        $username = $base;
        $limit = 10;
        do {
            if ($this->usernameAvailable($username)) {
                return $username;
            }
            $username = $base . rand(1000, 9999);
        } while ($limit--);
        throw new \Exception('Cannot get available username');
    }

    private function usernameAvailable(string $username): bool
    {
        if (User::existsByUsername($username)) {
            return false;
        }
        if ((new System())->isUsernameAvailable($username)) {
            return true;
        }
        return false;
    }

    private function callApi(string $method, string $uri, array|null $body = null): JsonResponse
    {
        // rootAccount(): on a fresh install there is no admins row to find.
        Auth::setUser(Admin::rootAccount());

        if (is_array($body)) {
            $body = json_encode($body);
        }

        $request = Request::create("/api$uri", $method, content: $body);
        $request->headers->set('Content-Type', 'application/json');
        App::instance('request', $request);

        $response = Route::dispatch($request);
        assert($response instanceof JsonResponse);
        return $response;
    }
}
