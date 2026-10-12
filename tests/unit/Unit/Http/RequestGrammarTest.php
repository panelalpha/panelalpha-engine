<?php

namespace Tests\Unit\Http;

use App\Http\Requests\DomainUpdateRequest;
use App\Http\Requests\EnvVarsUpdateRequest;
use App\Http\Requests\FtpAccountStoreRequest;
use App\Http\Requests\MysqlDatabaseRenameRequest;
use App\Http\Requests\MysqlDatabaseStoreRequest;
use App\Http\Requests\MysqlUserRenameRequest;
use App\Http\Requests\MysqlUserStoreRequest;
use App\Http\Requests\ProjectPushRequest;
use App\Http\Requests\SftpAccountStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Request fields that end up in a shell command or a server config file.
 * The store paths always had a strict grammar; these pin the update and
 * sibling paths to the same one, because they reach the same sinks.
 */
class RequestGrammarTest extends TestCase
{
    #[DataProvider('shellDomains')]
    public function test_project_update_rejects_a_domain_that_is_not_a_hostname(string $domain): void
    {
        $errors = $this->errors(new UserUpdateRequest(), ['domain' => $domain]);

        $this->assertArrayHasKey('domain', $errors, json_encode($domain));
    }

    #[DataProvider('shellDomains')]
    public function test_ftp_account_rejects_a_domain_that_is_not_a_hostname(string $domain): void
    {
        $errors = $this->errors(new FtpAccountStoreRequest(), [
            'user' => 'ftpuser',
            'domain' => $domain,
            'password' => 'correct-horse-battery',
        ]);

        $this->assertArrayHasKey('domain', $errors, json_encode($domain));
    }

    public static function shellDomains(): array
    {
        return [
            'command substitution' => ['x$(id>/tmp/pwn).com'],
            'semicolon' => ['a;touch /tmp/pwn;.com'],
            'backticks' => ['`id`.com'],
            'space' => ['a b.com'],
            'slash' => ['../etc/passwd.com'],
            'newline' => ["example.com\nrm -rf /"],
            'no tld' => ['localhost'],
            'numeric tld' => ['example.123'],
        ];
    }

    public function test_project_update_accepts_a_hostname(): void
    {
        $this->assertSame([], $this->errors(new UserUpdateRequest(), ['domain' => 'shop.example-site.co.uk']));
    }

    #[DataProvider('configDocumentRoots')]
    public function test_domain_update_rejects_a_document_root_with_config_syntax(string $root): void
    {
        $errors = $this->errors(new DomainUpdateRequest(), [
            'document_root' => $root,
            'redirect_enabled' => false,
            'force_https_redirect' => false,
        ]);

        $this->assertArrayHasKey('document_root', $errors, json_encode($root));
    }

    public static function configDocumentRoots(): array
    {
        return [
            'alias another account' => ['/public_html; } location /r { alias /home/victim/; autoindex on; } #'],
            'space' => ['/public html'],
            'newline' => ["/public_html\nroot /;"],
            'quote' => ['/public_html"'],
            'brace' => ['/public_html}'],
            'dollar' => ['/$document_root'],
            'parent segment' => ['/public_html/../../etc'],
            'relative' => ['public_html'],
        ];
    }

    #[DataProvider('plainDocumentRoots')]
    public function test_domain_update_accepts_a_plain_path(string $root): void
    {
        $errors = $this->errors(new DomainUpdateRequest(), [
            'document_root' => $root,
            'redirect_enabled' => false,
            'force_https_redirect' => false,
        ]);

        $this->assertArrayNotHasKey('document_root', $errors, json_encode($root));
    }

    public static function plainDocumentRoots(): array
    {
        return [
            'default layout' => ['/example.com/public_html'],
            'nested with dots and dashes' => ['/my-site.example.com/releases/v1.2/public'],
            'root of the account' => ['/'],
        ];
    }

    public function test_sftp_account_rejects_a_public_key_with_a_newline(): void
    {
        $errors = $this->errors(new SftpAccountStoreRequest(), [
            'username' => 'acme_sftp',
            'auth_method' => 'public_key',
            'public_key' => "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGxzZWNyZXQ\nrogue::0:0:/home/victim:",
        ]);

        $this->assertArrayHasKey('public_key', $errors);
    }

    public function test_sftp_account_accepts_an_openssh_key(): void
    {
        $errors = $this->errors(new SftpAccountStoreRequest(), [
            'username' => 'acme_sftp',
            'auth_method' => 'public_key',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGxzZWNyZXQ user@laptop',
        ]);

        $this->assertArrayNotHasKey('public_key', $errors);
    }

    #[DataProvider('configRedirects')]
    public function test_domain_update_rejects_a_redirect_nginx_would_interpret(string $url): void
    {
        $errors = $this->errors(new DomainUpdateRequest(), ['redirect_url' => $url]);

        $this->assertArrayHasKey('redirect_url', $errors, json_encode($url));
    }

    public static function configRedirects(): array
    {
        return [
            'variable' => ['https://evil.example/?c=$http_cookie'],
            'semicolon' => ['https://evil.example/;return'],
            'brace' => ['https://evil.example/{x}'],
        ];
    }

    public function test_domain_update_accepts_a_plain_redirect(): void
    {
        $errors = $this->errors(new DomainUpdateRequest(), ['redirect_url' => 'https://www.example.com/shop?a=1&b=2#top']);

        $this->assertArrayNotHasKey('redirect_url', $errors);
    }

    /** MCP skips TrimStrings, so a bare `$` let one trailing newline through. */
    #[DataProvider('namedFields')]
    public function test_a_name_with_a_trailing_newline_is_refused(string $request, string $field): void
    {
        $this->assertArrayNotHasKey($field, $this->errors(new $request(), [$field => 'acme_1']));
        $this->assertArrayHasKey($field, $this->errors(new $request(), [$field => "acme_1\n"]));
    }

    public static function namedFields(): array
    {
        return [
            'database' => [MysqlDatabaseStoreRequest::class, 'name'],
            'database rename' => [MysqlDatabaseRenameRequest::class, 'name'],
            'database user' => [MysqlUserStoreRequest::class, 'name'],
            'database user rename' => [MysqlUserRenameRequest::class, 'name'],
            'sftp account' => [SftpAccountStoreRequest::class, 'username'],
        ];
    }

    /** A name that reaches `.env` may not spell a second line or a shell metacharacter. */
    #[DataProvider('configEnvVarNames')]
    public function test_env_vars_rejects_a_key_that_is_not_a_name(string $key): void
    {
        $this->assertArrayHasKey('env_vars', $this->errors(new EnvVarsUpdateRequest(), ['env_vars' => [$key => 'value']]), json_encode($key));
    }

    public static function configEnvVarNames(): array
    {
        return [
            'leading digit' => ['1BAD'],
            'space' => ['MY KEY'],
            'newline' => ["FOO\nBAR"],
            'trailing newline' => ["FOO\n"],
            'equals' => ['A=B'],
            'dash' => ['A-B'],
            'empty' => [''],
        ];
    }

    public function test_env_vars_accepts_a_plain_name(): void
    {
        $this->assertSame([], $this->errors(new EnvVarsUpdateRequest(), ['env_vars' => ['APP_ENV' => 'production']]));
    }

    /** @return array<string, mixed> */
    private function errors(FormRequest $request, array $payload): array
    {
        $request->setContainer($this->app);

        return Validator::make($payload, $request->rules())->errors()->toArray();
    }

    #[DataProvider('tableNames')]
    public function test_push_rejects_a_table_name_that_reaches_the_command_line(string $name): void
    {
        $errors = $this->errors(new ProjectPushRequest(), [
            'tables' => [['database' => 'acme_wp', 'name' => $name]],
        ]);

        $this->assertArrayHasKey('tables.0.name', $errors, json_encode($name));
    }

    public static function tableNames(): array
    {
        return [
            'command substitution' => ['wp$(id>/tmp/pwn)'],
            'semicolon' => ['wp_posts;touch /tmp/pwn'],
            'backticks' => ['wp`id`posts'],
            'space' => ['wp posts'],
            'dash' => ['wp-posts'],
            'newline' => ["wp_posts\nDROP TABLE x"],
            'empty' => [''],
        ];
    }

    public function test_push_accepts_a_table_name_with_a_dollar_sign(): void
    {
        $errors = $this->errors(new ProjectPushRequest(), [
            'target' => 'acme',
            'tables' => [['database' => 'acme_wp', 'name' => 'wp_$posts']],
        ]);

        $this->assertArrayNotHasKey('tables.0.name', $errors);
    }

    public function test_push_refuses_more_than_five_hundred_tables(): void
    {
        $tables = [];
        for ($i = 0; $i <= 500; $i++) {
            $tables[] = ['database' => 'acme_wp', 'name' => 't' . $i];
        }

        $this->assertArrayHasKey('tables', $this->errors(new ProjectPushRequest(), [
            'target' => 'acme',
            'tables' => $tables,
        ]));
    }
}
