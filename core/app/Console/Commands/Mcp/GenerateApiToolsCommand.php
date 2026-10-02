<?php

namespace App\Console\Commands\Mcp;

use App\Mcp\Tools\Api\DownloadWindow;
use App\Mcp\Tools\Api\UploadArguments;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Writes one MCP tool per documented API operation, from the OpenAPI document
 * the #[OA\...] attributes already produce. Run l5-swagger:generate first.
 *
 * The generated classes are committed, not built at boot: they are greppable,
 * reviewable in a diff, and a change to the API surface shows up as added or
 * removed files rather than as silent behaviour.
 */
class GenerateApiToolsCommand extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['mcp:generate-api-tools'];

    protected $signature = 'mcp:tool:generate
                            {--spec=storage/api-docs/api-docs.json : OpenAPI document to read}
                            {--out=app/Mcp/Tools/Api : Directory to write tools into}
                            {--names=app/Mcp/tool-names.php : Map of "VERB /path" to tool name}
                            {--dry-run : Report what would be written and change nothing}';

    protected $description = 'Generate one MCP tool per documented engine API operation';

    /**
     * Operations whose verb is a write but whose effect is not.
     *
     * The verb is the right default and stays it: everything else that POSTs
     * to this API creates something. These are the exceptions where POST is
     * only how a body gets sent -- the operation reads, answers, and leaves
     * the server as it found it. Getting one wrong here would hand a
     * `MCP_PERMISSION_MODE=readonly` client a tool that writes, so the list is
     * explicit, per operation, and short.
     *
     * Public because the test that checks every tool's risk annotation has to
     * read the same list; two copies of it would be two chances to disagree.
     *
     * @var list<string>
     */
    public const READ_ONLY_OPERATIONS = [
        // Clones into a temp directory it deletes again, and reports what it
        // read. Nothing is deployed and no account is touched.
        'POST /source/inspect',
        // Answers whether a name is free. POST only because the name travels
        // in a body; it reserves nothing and writes nothing, and calling it a
        // write hid it from read-only clients and made every caller ask
        // permission to look something up.
        'POST /projects/verify-new-username',
    ];

    /**
     * API parameters the tools expose under another name: API parameter =>
     * tool argument, per operation, with `*` applying everywhere.
     *
     * The API takes a project as `username` on every route, a leftover from
     * when a project was called a user. The tools call things by what they
     * are, so a project is `name` -- always, on every tool, so a model never
     * has to remember which spelling this one takes. The three endpoints
     * whose body already had a `name` (the MySQL database or user being
     * created or renamed) expose that field under the name the sibling
     * routes use for the same thing in their path, `dbname` and `dbuser`, so
     * the entity is called the same across list/get/create/delete.
     *
     * Public because the tests read it: the check that no tool exposes
     * `username` and the check that a rename never collides.
     *
     * @var array<string, array<string, string>>
     */
    public const ARGUMENT_NAMES = [
        '*' => [
            'username' => 'name',
            'new_username' => 'new_name',
        ],
        'POST /projects/{username}/mysql/databases' => ['name' => 'dbname'],
        'POST /projects/{username}/mysql/users' => ['name' => 'dbuser'],
        'PUT /projects/{username}/mysql/users/{dbuser}/rename' => ['name' => 'new_dbuser'],
        // A bug report names the project it is about in its body, and the API
        // spells that `project` because next to `title` and `contact` a bare
        // `name` would read as the reporter's. The tools have no such
        // neighbours and one rule -- a project is `name` -- so it is `name`
        // here like everywhere else.
        'POST /bug-reports' => ['project' => 'name'],
    ];

    /** What a renamed project parameter says about itself when the API said nothing. */

    public function handle(): int
    {
        $specPath = base_path($this->option('spec'));

        if (!is_file($specPath)) {
            $this->error("OpenAPI document not found at {$specPath}. Run: php artisan l5-swagger:generate");

            return self::FAILURE;
        }

        $spec = json_decode((string)file_get_contents($specPath), true);

        if (!is_array($spec) || !isset($spec['paths'])) {
            $this->error('OpenAPI document could not be parsed.');

            return self::FAILURE;
        }

        $namesPath = base_path($this->option('names'));

        if (!is_file($namesPath)) {
            $this->error("Tool name map not found at {$namesPath}.");

            return self::FAILURE;
        }

        /** @var array<string, string> $map */
        $map = require $namesPath;

        $operations = $this->operations($spec['paths']);

        if (($failure = $this->checkNameMap($operations, $map)) !== null) {
            return $failure;
        }

        $names = [];
        $written = 0;
        $registry = [];

        foreach ($operations as $op) {
            $name = $map["{$op['method']} {$op['path']}"];
            $names[$name] = true;

            $group = $this->group($op['tags']);
            $class = $this->className($name);
            $dir = base_path($this->option('out')) . '/' . $group;
            $file = $dir . '/' . $class . '.php';

            if (!$this->option('dry-run')) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($file, $this->render($op, $name, $class, $group));
            }

            $registry[] = "App\\Mcp\\Tools\\Api\\{$group}\\{$class}";
            $written++;
        }

        $this->info(($this->option('dry-run') ? 'Would write ' : 'Wrote ') . $written . ' tools.');

        if (!$this->option('dry-run')) {
            file_put_contents(
                base_path($this->option('out')) . '/generated-tools.php',
                $this->renderRegistry($registry)
            );
            $this->info('Wrote the tool registry.');
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $paths
     * @return array<int, array<string, mixed>>
     */
    private function operations(array $paths): array
    {
        $out = [];

        foreach ($paths as $path => $item) {
            foreach ((array)$item as $verb => $op) {
                if (!in_array(strtoupper($verb), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }
                // x-mcp-hide: an operation the API keeps that no tool can
                // call usefully (a stream, an internal exchange). No tool, no name.
                if (($op['x-mcp-hide'] ?? false) === true) {
                    continue;
                }

                $out[] = [
                    'method' => strtoupper($verb),
                    'path' => $path,
                    'summary' => $op['summary'] ?? '',
                    'description' => $op['description'] ?? '',
                    'tags' => $op['tags'] ?? [],
                    'parameters' => $op['parameters'] ?? [],
                    'requestBody' => $op['requestBody'] ?? null,
                    'responses' => $op['responses'] ?? [],
                    'x-mcp-description' => $op['x-mcp-description'] ?? null,
                ];
            }
        }

        usort($out, fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return $out;
    }

    /**
     * Tool names are the client-facing contract, so they are declared in the map
     * rather than derived here. This refuses to generate anything until the map
     * and the API surface agree: an operation with no name would otherwise be
     * silently skipped (a tool quietly disappearing from every client), and a
     * stale entry is the trace of a route that moved.
     *
     * @param array<int, array<string, mixed>> $operations
     * @param array<string, string> $map
     */
    private function checkNameMap(array $operations, array $map): ?int
    {
        $keys = [];
        $missing = [];

        foreach ($operations as $op) {
            $key = "{$op['method']} {$op['path']}";
            $keys[$key] = true;

            if (!isset($map[$key])) {
                $missing[] = [$key, $this->suggestName($op['method'], $op['path'])];
            }
        }

        $stale = array_diff_key($map, $keys);
        $duplicates = array_keys(array_filter(array_count_values($map), fn (int $n): bool => $n > 1));

        if ($missing === [] && $stale === [] && $duplicates === []) {
            return null;
        }

        if ($missing !== []) {
            $this->error(count($missing) . ' operation(s) have no name in ' . $this->option('names') . ':');
            foreach ($missing as [$key, $suggestion]) {
                $this->line("    '{$key}' => '{$suggestion}',");
            }
            $this->line('');
            $this->comment('Those are suggestions in the house style, not decisions -- read them before pasting.');
        }

        if ($stale !== []) {
            $this->error(count($stale) . ' name(s) refer to an operation that no longer exists:');
            foreach ($stale as $key => $name) {
                $this->line("    '{$key}' => '{$name}',");
            }
            $this->line('');
            $this->comment('Removing one of these removes a tool clients may be calling by name.');
        }

        if ($duplicates !== []) {
            $this->error('Name used by more than one operation: ' . implode(', ', $duplicates));
        }

        return self::FAILURE;
    }

    /**
     * A convention-shaped starting point for a name the map is missing:
     * <resource>_<action>, parameters dropped. Only ever printed as a
     * suggestion -- the map is what generation actually reads.
     */
    private function suggestName(string $method, string $path): string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn (string $s): bool => $s !== ''));
        $last = end($segments) ?: '';
        $lastIsParam = str_starts_with($last, '{');

        $nouns = [];
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '{')) {
                continue;
            }
            $nouns[] = Str::snake(Str::singular(str_replace('-', '_', $segment)));
        }

        $action = match ($method) {
            'GET' => $lastIsParam ? 'get' : 'list',
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => strtolower($method),
        };

        // A trailing verb segment (suspend, rebuild, rename) already names the
        // action; using it beats appending a generic one. Taken before the
        // project prefix is dropped, so /projects/{username}/suspend suggests
        // project_suspend rather than suspend_update.
        if (!$lastIsParam && count($nouns) > 1 && $method !== 'GET') {
            $action = (string)array_pop($nouns);
        }

        // Everything hangs off the project, so repeating it is noise -- unless
        // dropping it would leave the name with nothing to act on.
        if (count($nouns) > 1 && $nouns[0] === 'project') {
            array_shift($nouns);
        }

        return implode('_', array_merge($nouns, [$action]));
    }

    private function className(string $toolName): string
    {
        return Str::studly($toolName) . 'Tool';
    }

    /**
     * @param array<int, string> $tags
     */
    private function group(array $tags): string
    {
        return Str::studly(str_replace(['-', ' '], '_', $tags[0] ?? 'General'));
    }

    /**
     * @param array<string, mixed> $op
     */
    private function render(array $op, string $name, string $class, string $group): string
    {
        [$pathParams, $queryParams, $bodyParams, $fileParams, $schema, $argumentNames, $defaults] = $this->parameters($op);

        $annotations = $this->annotations($op['method'], $op['path']);
        $description = $this->description($op);

        $uses = array_merge(
            [
                'App\\Mcp\\Tools\\Api\\ApiTool',
                'Illuminate\\Contracts\\JsonSchema\\JsonSchema',
                'Laravel\\Mcp\\Server\\Attributes\\Description',
                'Laravel\\Mcp\\Server\\Attributes\\Name',
            ],
            array_map(fn (string $a): string => "Laravel\\Mcp\\Server\\Tools\\Annotations\\{$a}", $annotations)
        );
        sort($uses);

        $useBlock = implode("\n", array_map(fn (string $u): string => "use {$u};", $uses));
        $attrBlock = implode("\n", array_map(fn (string $a): string => "#[{$a}]", $annotations));

        $schemaBody = $schema === []
            ? "        return [];"
            : "        return [\n" . implode("\n", $schema) . "\n        ];";

        $methods = [];
        $methods[] = $this->arrayMethod('pathParams', $pathParams);
        $methods[] = $this->arrayMethod('queryParams', $queryParams);
        $methods[] = $this->arrayMethod('bodyParams', $bodyParams);
        $methods[] = $this->arrayMethod('fileParams', $fileParams);
        $methods[] = $this->mapMethod('argumentNames', $argumentNames);
        $methods[] = $this->mapMethod('defaults', $defaults);
        $methods[] = $this->returnsFile($op) ? <<<'PHP'
                protected function returnsFile(): bool
                {
                    return true;
                }
            PHP : '';
        $extra = implode("\n\n", array_filter($methods));

        return <<<PHP
        <?php

        // Generated by `php artisan mcp:tool:generate` from the OpenAPI
        // document. Do not edit by hand -- change the #[OA\\...] attributes on
        // the controller and regenerate.

        namespace App\\Mcp\\Tools\\Api\\{$group};

        {$useBlock}

        #[Name('{$name}')]
        #[Description({$description})]
        {$attrBlock}
        class {$class} extends ApiTool
        {
            protected function method(): string
            {
                return '{$op['method']}';
            }

            protected function path(): string
            {
                return '{$op['path']}';
            }

        {$extra}

            /**
             * @return array<string, \\Illuminate\\JsonSchema\\Types\\Type>
             */
            public function schema(JsonSchema \$schema): array
            {
        {$schemaBody}
            }
        }

        PHP;
    }

    /**
     * @param array<int, string> $values
     */
    /**
     * @param array<string, mixed> $op
     */
    private function returnsFile(array $op): bool
    {
        foreach ((array)($op['responses']['200']['content'] ?? []) as $media) {
            if ((is_array($media) ? ($media['schema']['format'] ?? null) : null) === 'binary') {
                return true;
            }
        }

        return false;
    }

    private function arrayMethod(string $name, array $values): string
    {
        if ($values === []) {
            return '';
        }

        $items = implode("\n", array_map(fn (string $v): string => "            '{$v}',", $values));

        return <<<PHP
            /**
             * @return array<int, string>
             */
            protected function {$name}(): array
            {
                return [
        {$items}
                ];
            }
        PHP;
    }

    /**
     * @param array<string, string> $values
     */
    private function mapMethod(string $name, array $values): string
    {
        if ($values === []) {
            return '';
        }

        $items = implode("\n", array_map(
            fn (string $k, string $v): string => "            '{$k}' => '{$v}',",
            array_keys($values),
            $values
        ));

        return <<<PHP
            /**
             * @return array<string, string>
             */
            protected function {$name}(): array
            {
                return [
        {$items}
                ];
            }
        PHP;
    }

    /**
     * The name the tool exposes for an API parameter of this operation.
     */
    private function argumentName(array $op, string $apiName): string
    {
        $renames = array_merge(
            self::ARGUMENT_NAMES['*'],
            self::ARGUMENT_NAMES["{$op['method']} {$op['path']}"] ?? []
        );

        return $renames[$apiName] ?? $apiName;
    }

    /**
     * The schema line for one parameter, under the name the tool exposes. A
     * renamed parameter says which API parameter it feeds, so a reader of the
     * API docs and a reader of the tool schema can tell they are the same.
     *
     * @param array<string, mixed> $definition
     * @param array<string, string> $argumentNames Filled in for renamed parameters.
     */
    private function argumentLine(
        array $op,
        string $apiName,
        array $definition,
        bool $required,
        ?string $description,
        array &$argumentNames
    ): string {
        $argument = $this->argumentName($op, $apiName);
        $suffix = null;

        if ($argument !== $apiName) {
            $argumentNames[$argument] = $apiName;

            // name -> username is on every tool and the server instructions
            // explain it once; repeating it cost ~200 schemas a sentence each.
            if ($apiName !== 'username') {
                $suffix = "Sent to the API as `{$apiName}`.";
            }
        }

        return $this->schemaLine($argument, $definition, $required, $description, $suffix);
    }

    /**
     * @param array<string, mixed> $op
     * @return array{0: array<int,string>, 1: array<int,string>, 2: array<int,string>, 3: array<int,string>, 4: array<int,string>, 5: array<string,string>, 6: array<string,string>}
     */
    private function parameters(array $op): array
    {
        $path = $query = $body = $files = [];
        $schema = [];
        $seen = [];
        $argumentNames = [];
        $defaults = [];

        foreach ((array)$op['parameters'] as $p) {
            if (!is_array($p) || !isset($p['name'])) {
                continue;
            }

            $in = $p['in'] ?? 'query';
            $name = $p['name'];

            if ($in === 'path') {
                $path[] = $name;
            } elseif ($in === 'query') {
                $query[] = $name;
            } else {
                continue;
            }

            $seen[$name] = true;
            $definition = (array)($p['schema'] ?? []);
            // On a parameter the extensions sit on the parameter, not its schema.
            if (is_scalar($p['x-mcp-default'] ?? null)) {
                $definition['x-mcp-default'] = $p['x-mcp-default'];
                $defaults[$name] = (string)$p['x-mcp-default'];
            }
            if (is_string($p['x-mcp-description'] ?? null)) {
                $definition['x-mcp-description'] = $p['x-mcp-description'];
            }
            $schema[] = $this->argumentLine(
                $op,
                $name,
                $definition,
                (bool)($p['required'] ?? $in === 'path'),
                $p['description'] ?? null,
                $argumentNames
            );
        }

        $bodySchema = $op['requestBody']['content']['application/json']['schema'] ?? null;
        $required = (array)($bodySchema['required'] ?? []);

        foreach ((array)($bodySchema['properties'] ?? []) as $name => $prop) {
            // x-mcp-hide: a field the API keeps that the tool does not offer,
            // because another tool covers it and every listed field costs every
            // client on every connect.
            if (is_array($prop) && ($prop['x-mcp-hide'] ?? false) === true) {
                continue;
            }
            if (isset($seen[$name])) {
                // A body field shadowing a path parameter would be ambiguous in
                // the flat tool schema; the path wins, the body copy is dropped.
                continue;
            }

            $body[] = $name;
            $seen[$name] = true;
            $schema[] = $this->argumentLine(
                $op,
                $name,
                is_array($prop) ? $prop : [],
                in_array($name, $required, true),
                is_array($prop) ? ($prop['description'] ?? null) : null,
                $argumentNames
            );
            // An agent-only default, e.g. `dind` for a project an agent creates.
            if (is_array($prop) && is_scalar($prop['x-mcp-default'] ?? null)) {
                $defaults[$name] = (string)$prop['x-mcp-default'];
            }
        }

        // A multipart body: plain fields travel as form parameters, and each
        // binary field becomes the virtual arguments UploadArguments describes,
        // because a tool call cannot carry bytes any other way.
        $multipart = $op['requestBody']['content']['multipart/form-data']['schema'] ?? null;
        $multipartRequired = (array)($multipart['required'] ?? []);

        foreach ((array)($multipart['properties'] ?? []) as $name => $prop) {
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $prop = is_array($prop) ? $prop : [];

            if (($prop['format'] ?? null) === 'binary') {
                $files[] = $name;
                foreach (UploadArguments::describe($name) as [$virtual, $definition, $required, $description]) {
                    $schema[] = $this->schemaLine($virtual, $definition, $required, $description);
                }
                continue;
            }

            $body[] = $name;
            $schema[] = $this->argumentLine(
                $op,
                $name,
                $prop,
                in_array($name, $multipartRequired, true),
                $prop['description'] ?? null,
                $argumentNames
            );
        }

        // A file response is read one window at a time; see DownloadWindow.
        if ($this->returnsFile($op)) {
            foreach (DownloadWindow::describe() as [$virtual, $definition, $required, $description]) {
                $schema[] = $this->schemaLine($virtual, $definition, $required, $description);
            }
        }

        // A rename that lands on a name the operation already uses would make
        // one argument feed two parameters. That is an entry to add to
        // ARGUMENT_NAMES for this operation, and generation stops until it is.
        $exposed = array_map(fn (string $n): string => $this->argumentName($op, $n), [...$path, ...$query, ...$body]);
        foreach ($files as $file) {
            array_push($exposed, ...UploadArguments::virtualNames($file));
        }
        if ($this->returnsFile($op)) {
            array_push($exposed, ...DownloadWindow::names());
        }
        $clashes = array_keys(array_filter(array_count_values($exposed), fn (int $n): bool => $n > 1));
        if ($clashes !== []) {
            throw new \RuntimeException(
                "{$op['method']} {$op['path']}: more than one parameter would be exposed as "
                . implode(', ', $clashes) . '. Add a rename for this operation to ARGUMENT_NAMES.'
            );
        }

        return [$path, $query, $body, $files, $schema, $argumentNames, $defaults];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function schemaLine(
        string $name,
        array $definition,
        bool $required,
        ?string $description,
        ?string $suffix = null
    ): string {
        $type = $definition['type'] ?? 'string';

        $call = match ($type) {
            'integer' => '$schema->integer()',
            'number' => '$schema->number()',
            'boolean' => '$schema->boolean()',
            'array' => '$schema->array()',
            'object' => '$schema->object()',
            default => '$schema->string()',
        };

        // The bounds the API documents, so a client can check them before calling.
        [$lower, $upper] = in_array($type, ['integer', 'number'], true)
            ? ['minimum', 'maximum']
            : ['minLength', 'maxLength'];
        if (in_array($type, ['integer', 'number', 'string'], true)) {
            foreach ([$lower => 'min', $upper => 'max'] as $key => $method) {
                if (is_int($definition[$key] ?? null)) {
                    $call .= "->{$method}({$definition[$key]})";
                }
            }
        }

        // The tool's own wording, where the API docs need more than a model does.
        if (is_string($definition['x-mcp-description'] ?? null)) {
            $description = $definition['x-mcp-description'];
        }

        $notes = [];
        if (is_string($description) && trim($description) !== '') {
            $notes[] = trim($description);
        }
        if (isset($definition['enum']) && is_array($definition['enum'])) {
            $notes[] = 'One of: ' . implode(', ', array_map('strval', $definition['enum'])) . '.';
        }
        if (is_scalar($definition['x-mcp-default'] ?? null)) {
            $notes[] = 'This tool sends ' . $definition['x-mcp-default'] . ' when it is omitted.';
        }
        // A number or a flag is its type; an example only helps a string.
        if (isset($definition['example']) && !in_array($type, ['boolean', 'integer', 'number'], true)) {
            $example = $definition['example'];
            $notes[] = 'Example: ' . match (true) {
                is_scalar($example) => (string)$example,
                default => json_encode($example),
            } . '.';
        }
        if ($suffix !== null) {
            $notes[] = $suffix;
        }

        if ($notes !== []) {
            $call .= "->description('" . $this->escape(implode(' ', $notes)) . "')";
        }

        if ($required) {
            $call .= '->required()';
        }

        return "            '{$name}' => {$call},";
    }

    /**
     * @return array<int, string>
     */
    private function annotations(string $method, string $path): array
    {
        if ($method === 'GET' || self::readsOnly($method, $path)) {
            return ['IsReadOnly', 'IsIdempotent'];
        }

        if ($method === 'DELETE') {
            return ['IsDestructive', 'IsIdempotent'];
        }

        if ($method === 'PUT') {
            return ['IsDestructive', 'IsIdempotent'];
        }

        return ['IsDestructive'];
    }

    private static function readsOnly(string $method, string $path): bool
    {
        return in_array($method . ' ' . $path, self::READ_ONLY_OPERATIONS, true);
    }

    /**
     * @param array<string, mixed> $op
     */
    private function description(array $op): string
    {
        $summary = trim((string)$op['summary']);
        // x-mcp-description replaces the long form for the tool, which is read
        // on every connect; the API docs keep theirs.
        $extra = trim((string)($op['x-mcp-description'] ?? $op['description']));

        $text = $summary !== '' ? $summary : "{$op['method']} {$op['path']}";

        if ($extra !== '' && $extra !== $summary) {
            $text .= "\n\n" . $extra;
        }

        // No "Calls <VERB> /api/..." footer: every client reads every listed
        // description on connect, and the annotations already say what a
        // tool changes. The endpoint is in the class, for a human who needs it.

        $indented = implode("\n", array_map(
            fn (string $line): string => $line === '' ? '' : '    ' . $line,
            explode("\n", $text)
        ));

        return "<<<'MARKDOWN'\n    " . ltrim($indented) . "\n    MARKDOWN";
    }

    private function escape(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }

    /**
     * @param array<int, string> $classes
     */
    private function renderRegistry(array $classes): string
    {
        $lines = implode("\n", array_map(fn (string $c): string => "    \\{$c}::class,", $classes));

        return <<<PHP
        <?php

        // Generated by `php artisan mcp:tool:generate`. Do not edit by hand.
        //
        // One entry per documented engine API operation. EngineServer merges this
        // into its tool list, so regenerating is what adds or removes tools.

        return [
        {$lines}
        ];

        PHP;
    }
}
