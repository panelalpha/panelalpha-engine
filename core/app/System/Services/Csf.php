<?php

namespace App\System\Services;

use App\System\ProcessRunner;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ConfigServer Firewall on the Engine host (/etc/csf).
 *
 * @psalm-type ParsedCsfRule = array{
 *   protocol: ?string,
 *   direction: ?string,
 *   port_prefix: ?string,
 *   port: ?string,
 *   target_prefix: ?string,
 *   target: ?string,
 *   comment: ?string,
 *   raw: string,
 *   line_md5: string,
 * }
 *
 * @psalm-type NewCsfRule = array{
 *   protocol: ?string,
 *   direction: ?string,
 *   port_prefix: ?string,
 *   port: ?string,
 *   target_prefix: ?string,
 *   target: string,
 *   comment: ?string,
 * }
 */
class Csf
{
    public function __construct(
        private ProcessRunner $system,
    ) {
    }

    /** Whether csf.conf has the web UI on (`UI = "1"`); scripts/csf.sh leaves it off unless CSF_UI=1. */
    public function uiEnabled(): bool
    {
        return preg_match('/^UI\s*=\s*"1"/m', $this->system->execOnHost(['cat', '/etc/csf/csf.conf'])) === 1;
    }

    public function listRules(): array
    {
        $allow = $this->system->execOnHost([
            'cat',
            '/etc/csf/csf.allow',
        ]);

        $deny = $this->system->execOnHost([
            'cat',
            '/etc/csf/csf.deny',
        ]);

        return [
            'allow' => $this->parseRules($allow),
            'deny' => $this->parseRules($deny),
        ];
    }

    /**
     * @psalm-return array<ParsedCsfRule>
     */
    public function parseRules(string $rules): array
    {
        $parsed = [];
        $lines = explode("\n", $rules);
        foreach ($lines as $line) {
            $parsedLine = $this->parseRule($line);
            if ($parsedLine) {
                $parsed[] = $parsedLine;
            }
        }
        return $parsed;
    }

    /**
     * @psalm-return ?ParsedCsfRule
     */
    public function parseRule(string $line): ?array
    {
        $parsedLine = [
            'protocol' => null,
            'direction' => null,
            'port_prefix' => null,
            'port' => null,
            'target_prefix' => null,
            'target' => null,
            'comment' => null,
            'raw' => $line,
            'line_md5' => md5($line),
        ];
        $line = trim($line);
        if (empty($line)) {
            return null;
        }
        if (Str::startsWith($line, "#")) {
            return null;
        }

        $parts = explode(" #", $line, 2);
        if (count($parts) > 1) {
            $parsedLine['comment'] = trim($parts[1]);
        }
        $ruleParts = explode("|", trim($parts[0]));

        if (count($ruleParts) === 1) {
            $parsedLine['target'] = $ruleParts[0];
            return $parsedLine;
        }

        if (count($ruleParts) !== 4) {
            // unrecognized syntax
            return $parsedLine;
        }

        $portParts = explode("=", $ruleParts[2]);
        if (count($portParts) !== 2 || !in_array($portParts[0], ['s', 'd'])) {
            // unrecognized syntax
            return $parsedLine;
        }

        $targetParts = explode("=", $ruleParts[3]);
        if (count($targetParts) !== 2 || !in_array($targetParts[0], ['s', 'd', 'u'])) {
            // unrecognized syntax
            return $parsedLine;
        }

        $parsedLine['protocol'] = $ruleParts[0];
        $parsedLine['direction'] = $ruleParts[1];
        $parsedLine['port_prefix'] = $portParts[0] . "=";
        $parsedLine['port'] = $portParts[1];
        $parsedLine['target_prefix'] = $targetParts[0] . "=";
        $parsedLine['target'] = $targetParts[1];
        return $parsedLine;
    }

    /**
     * @param array{
     *   protocol: ?string,
     *   direction: ?string,
     *   port_prefix: ?string,
     *   port: ?string,
     *   target_prefix: ?string,
     *   target: string,
     *   comment: ?string
     * } $params
     */
    public function unparseRule(array $params): string
    {
        // Each rule is one line of csf.allow/csf.deny, whoever the caller is.
        foreach ($params as $field => $value) {
            if (is_string($value) && preg_match('/[\r\n]/', $value) === 1) {
                throw ValidationException::withMessages([
                    (string) $field => 'Must not contain a line break.',
                ]);
            }
        }

        $suffix = "";
        if ($params['comment'] !== null) {
            $suffix = " # " . $params['comment'];
        }

        $portFields = ['protocol', 'direction', 'port_prefix', 'port', 'target_prefix'];
        $missing = array_filter($portFields, fn (string $field): bool => $params[$field] === null);
        if (count($missing) === count($portFields)) {
            return $params['target'] . $suffix;
        }
        // Writing the bare target here would allow it on every port.
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'port' => 'A port rule needs ' . implode(', ', $missing) . ' as well.',
            ]);
        }

        $parts = [
            $params['protocol'],
            $params['direction'],
            $params['port_prefix'] . $params['port'],
            $params['target_prefix'] . $params['target'],
        ];

        return implode("|", $parts) . $suffix;
    }

    /**
     * @psalm-param NewCsfRule $params
     */
    public function addRule(string $type, array $params): array
    {
        if (!in_array($type, ['allow', 'deny'])) {
            throw new \Exception('Invalid rule type');
        }

        $line = $this->unparseRule($params);
        $parsed = $this->parseRule($line);
        if (!$parsed) {
            throw new \Exception('Failed to parse rule');
        }
        $path = '/etc/csf/csf.' . $type;

        $line = escapeshellarg($line);
        $path = escapeshellarg($path);

        $this->system->execOnHost([
            "bash",
            "-c",
            "echo $line >> $path",
        ]);

        return $parsed;
    }

    /**
     * @psalm-return ?ParsedCsfRule
     */
    public function findRule(string $type, string $lineMd5): ?array
    {
        $rules = $this->system->execOnHost([
            'cat',
            '/etc/csf/csf.' . $type,
        ]);
        $parsed = $this->parseRules($rules);
        foreach ($parsed as $rule) {
            if ($rule['line_md5'] === $lineMd5) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * @psalm-param array{target: string, protocol?: ?string, direction?: ?string, port_prefix?: ?string, port?: ?string, target_prefix?: ?string, comment?: ?string} $params
     */
    public function editRule(string $type, string $lineMd5, array $params): array
    {
        if (!in_array($type, ['allow', 'deny'])) {
            throw new \Exception('Invalid rule type');
        }

        $rule = $this->findRule($type, $lineMd5);
        if (!$rule) {
            throw new NotFoundHttpException('Cannot find rule');
        }

        // A field the caller did not send keeps its current value; nulling
        // them would turn `tcp|in|d=22|s=IP` into a bare IP allowed on every port.
        $fields = ['protocol', 'direction', 'port_prefix', 'port', 'target_prefix', 'target', 'comment'];
        /** @psalm-var NewCsfRule $params */
        $params = array_merge(array_intersect_key($rule, array_flip($fields)), $params);

        $newLine = $this->unparseRule($params);
        $newRule = $this->parseRule($newLine);
        if (!$newRule) {
            throw new \Exception('Failed to parse rule');
        }

        $filePath = '/etc/csf/csf.' . $type;

        $rules = $this->system->execOnHost([
            'cat',
            $filePath,
        ]);

        $lines = explode("\n", $rules);
        $newLines = [];
        foreach ($lines as $line) {
            if (md5($line) === $lineMd5) {
                $newLines[] = $newLine;
                continue;
            }
            $newLines[] = $line;
        }

        $newRules = implode("\n", $newLines);
        $tmpFile = tempnam(sys_get_temp_dir(), 'tmp_');
        if ($tmpFile === false) {
            throw new \Exception("Could not update '{$filePath}': tempnam() returned false");
        }
        file_put_contents($tmpFile, $newRules);
        $process = $this->system->runProcess(['sudo', 'cp', $tmpFile, $filePath]);
        unlink($tmpFile);

        if ($process->getExitCode() !== 0) {
            throw new \Exception("Could not update '{$filePath}': " . ($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $newRule;
    }

    /**
     * @psalm-return ParsedCsfRule
     */
    public function deleteRule(string $type, string $lineMd5): array
    {
        if (!in_array($type, ['allow', 'deny'])) {
            throw new \Exception('Invalid rule type');
        }

        $rule = $this->findRule($type, $lineMd5);
        if (!$rule) {
            throw new NotFoundHttpException('Cannot find rule');
        }

        $filePath = '/etc/csf/csf.' . $type;

        $rules = $this->system->execOnHost([
            'cat',
            $filePath,
        ]);

        $lines = explode("\n", $rules);
        $newLines = [];
        foreach ($lines as $line) {
            if (md5($line) === $lineMd5) {
                continue;
            }
            $newLines[] = $line;
        }

        $newRules = implode("\n", $newLines);
        $tmpFile = tempnam(sys_get_temp_dir(), 'tmp_');
        if ($tmpFile === false) {
            throw new \Exception("Could not update '{$filePath}': tempnam() returned false");
        }
        file_put_contents($tmpFile, $newRules);
        $process = $this->system->runProcess(['sudo', 'cp', $tmpFile, $filePath]);
        unlink($tmpFile);

        if ($process->getExitCode() !== 0) {
            throw new \Exception("Could not update '{$filePath}': " . ($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $rule;
    }

    private function awkReplaceLineInFile(string $search, string $replace, string $filePath): void
    {
        // escaping is hard
        $replace = str_replace('\\', '\\\\', $replace);

        $script = <<<'BASH'
search="$1"
replace="$2"
file="$3"
awk -v s="$search" -v r="$replace" '
BEGIN { RS = ORS = "\n" }
{
  if ($0 == s) {
    print r;
  } else {
    print $0;
  }
}' "$file" > "${file}.tmp" # && mv "${file}.tmp" "$file"
BASH;

        $this->system->execOnHost([
            "bash",
            "-c",
            $script,
            "--",
            $search,
            $replace,
            $filePath,
        ]);
    }

    public function getStatus(): array
    {
        $result = [
            'enabled' => null,
            'version' => null,
            'error' => null,
        ];

        $process = $this->system->runProcessOnHost(['csf', '-v']);

        if ($process->getExitCode() !== 0) {
            $result['error'] = $process->getErrorOutput() ?: $process->getOutput();
            return $result;
        }

        $result['enabled'] = !Str::contains($process->getOutput(), 'csf and lfd have been disabled');
        $result['version'] = Str::afterLast(trim($process->getOutput()), "\n");
        return $result;
    }

    public function restart(): void
    {
        $process = $this->system->runProcessOnHost(['csf', '-r']);

        if ($process->getExitCode() !== 0) {
            throw new \Exception(Str::afterLast(trim($process->getOutput()), "\n"));
        }
    }

    public function enable(): void
    {
        $process = $this->system->runProcessOnHost(['csf', '-e']);

        if ($process->getExitCode() !== 0) {
            throw new \Exception(Str::afterLast(trim($process->getOutput()), "\n"));
        }
    }

    public function disable(): void
    {
        $process = $this->system->runProcessOnHost(['csf', '-x']);

        if ($process->getExitCode() !== 0) {
            throw new \Exception(Str::afterLast(trim($process->getOutput()), "\n"));
        }
    }
}
