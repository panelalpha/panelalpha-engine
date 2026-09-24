<?php

namespace App\Mcp;

use App\Mcp\Tools\Api\ApiTool;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Decides which tools the MCP server exposes, from config/mcp-tools.php.
 *
 * Filtering happens before registration, so a tool that is off is not merely
 * hidden from tools/list -- it is not on the server at all, and calling it by
 * name returns "tool not found". Anything else would be a listing convention
 * rather than a control.
 */
class ToolPolicy
{
    public const MODE_READONLY = 'readonly';
    public const MODE_MODIFY = 'modify';
    public const MODE_FULL = 'full';

    /**
     * Verbs each permission mode permits. A read is allowed in every mode, and
     * so is an operation that only reads — see {@see readsOnly()}.
     */
    private const MODE_VERBS = [
        self::MODE_READONLY => ['GET'],
        self::MODE_MODIFY => ['GET', 'POST', 'PUT', 'PATCH'],
        self::MODE_FULL => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    ];

    /**
     * Reads that hand out a live credential. They change nothing, but a
     * readonly client may look, not log in, so these start at modify.
     */
    public const CREDENTIAL_TOOLS = [
        'csf_ui_credentials',      // CSF_UI_PASSWORD
        'system_exim_config_get',  // smarthost SMTP/SES/Mailchannels passwords, SendGrid token
        'app_sso_login',           // redeems an SSO token into the app's admin session cookie
    ];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config = [])
    {
        $this->config = $config ?: (array)config('mcp-tools', []);
    }

    /**
     * @param array<int, class-string<Tool>> $tools
     * @return array<int, class-string<Tool>>
     */
    public function filter(array $tools): array
    {
        $toolsets = $this->listOf('toolsets', 'all');
        $allowed = $this->listOf('tools');
        $denied = $this->listOf('denied');
        $regex = $this->regex();
        $mode = $this->mode();

        $everyToolset = $toolsets === [] || in_array('all', $toolsets, true);

        $kept = [];

        foreach ($tools as $class) {
            $name = $this->nameOf($class);

            $enabled = $everyToolset
                || in_array($this->toolsetOf($class), $toolsets, true)
                || in_array($name, $allowed, true);

            if (!$enabled) {
                continue;
            }

            if (!$this->permittedBy($mode, $class)) {
                continue;
            }

            if ($this->matchesAny($name, $denied)) {
                continue;
            }

            if ($regex !== null && preg_match($regex, $name) === 1) {
                continue;
            }

            $kept[] = $class;
        }

        return $kept;
    }

    /**
     * The group a tool belongs to: the namespace segment it sits in, which for
     * the generated tools is the OpenAPI tag their endpoint is documented
     * under. Hand-written tools live directly under App\Mcp\Tools and are
     * grouped as "engine".
     *
     * @param class-string<Tool> $class
     */
    public function toolsetOf(string $class): string
    {
        $relative = Str::after($class, 'App\\Mcp\\Tools\\');
        $segments = explode('\\', $relative);

        // ["Api", "Projects", "ProjectListTool"] -> projects;
        // ["ProjectListSummaryTool"] -> engine
        return count($segments) >= 3
            ? strtolower($segments[count($segments) - 2])
            : 'engine';
    }

    /**
     * @param class-string<Tool> $class
     */
    public function nameOf(string $class): string
    {
        return (new $class())->name();
    }

    /**
     * The HTTP verb behind a tool, or null when it is not an API tool. The two
     * hand-written tools only read, so they count as GET.
     *
     * @param class-string<Tool> $class
     */
    public function verbOf(string $class): string
    {
        $tool = new $class();

        return $tool instanceof ApiTool ? strtoupper($tool->httpMethod()) : 'GET';
    }

    /**
     * Whether a tool only reads, whatever verb it uses.
     *
     * The verb is the right default and stays it: everything else that POSTs
     * to this API creates something. The exceptions are the operations where
     * POST is only how a body gets sent — a git token has no business in a
     * query string — and the call reads, answers and leaves the server as it
     * found it. Those carry `#[IsReadOnly]`, written onto the generated class
     * from the short explicit list in
     * {@see \App\Console\Commands\Mcp\GenerateApiToolsCommand::READ_ONLY_OPERATIONS}.
     * So this is a declaration made per operation at generation time, not a
     * guess made from a tool's name at runtime.
     *
     * @param class-string<Tool> $class
     */
    public function readsOnly(string $class): bool
    {
        if ($this->verbOf($class) === 'GET') {
            return true;
        }

        try {
            return ((new $class())->annotations()['readOnlyHint'] ?? false) === true;
        } catch (Throwable $e) {
            // An unreadable annotation must not widen what a mode allows.
            Log::warning('Could not read the annotations of an MCP tool; treating it as a write.', [
                'tool' => $class,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * What a tool does to the server, for display: `read` or `write`.
     *
     * @param class-string<Tool> $class
     */
    public function accessOf(string $class): string
    {
        if ($this->revealsCredential($class)) {
            return 'secret';
        }

        return $this->readsOnly($class) ? 'read' : 'write';
    }

    /**
     * @param class-string<Tool> $class
     */
    public function revealsCredential(string $class): bool
    {
        return in_array($this->nameOf($class), self::CREDENTIAL_TOOLS, true);
    }

    /**
     * Whether this configuration's ceiling lets the tool through, whatever
     * toolsets and denials then do with it.
     *
     * @param class-string<Tool> $class
     */
    public function permits(string $class): bool
    {
        return $this->permittedBy($this->mode(), $class);
    }

    /**
     * @param class-string<Tool> $class
     */
    private function permittedBy(string $mode, string $class): bool
    {
        if ($this->revealsCredential($class)) {
            return $mode !== self::MODE_READONLY;
        }

        // A read is allowed in every mode, so a read-only POST is admitted by
        // readonly rather than being filtered out for the verb it had to use.
        return $this->readsOnly($class)
            || in_array($this->verbOf($class), self::MODE_VERBS[$mode], true);
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string)($this->config['permission_mode'] ?? self::MODE_FULL)));

        if (!isset(self::MODE_VERBS[$mode])) {
            Log::warning('Unknown MCP_PERMISSION_MODE, falling back to readonly.', [
                'given' => $mode,
                'expected' => array_keys(self::MODE_VERBS),
            ]);

            // An unreadable setting must not silently grant more than intended.
            return self::MODE_READONLY;
        }

        return $mode;
    }

    /**
     * @param array<int, string> $patterns
     */
    private function matchesAny(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    private function regex(): ?string
    {
        $raw = trim((string)($this->config['denied_regex'] ?? ''));

        if ($raw === '') {
            return null;
        }

        $pattern = '/' . str_replace('/', '\\/', $raw) . '/i';

        try {
            if (@preg_match($pattern, '') === false) {
                throw new \RuntimeException('invalid pattern');
            }
        } catch (Throwable) {
            Log::warning('MCP_DENIED_TOOLS_REGEX is not a valid regular expression and was ignored.', [
                'pattern' => $raw,
            ]);

            return null;
        }

        return $pattern;
    }

    /**
     * @return array<int, string>
     */
    private function listOf(string $key, string $default = ''): array
    {
        $raw = $this->config[$key] ?? $default;

        if (is_array($raw)) {
            $values = $raw;
        } else {
            $raw = trim((string)$raw);
            $values = $raw === '' ? [] : explode(',', $raw);
        }

        return array_values(array_filter(array_map(
            fn (mixed $v): string => strtolower(trim((string)$v)),
            $values
        ), fn (string $v): bool => $v !== ''));
    }
}
