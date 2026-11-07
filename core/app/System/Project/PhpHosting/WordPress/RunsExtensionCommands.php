<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * The WP-CLI calls plugins and themes make alike; each class passes its own command words.
 */
trait RunsExtensionCommands
{
    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    private function withFields(array $args, ?string $fields): array
    {
        if ($fields !== null) {
            $args[] = '--fields='.$fields;
        }

        return $args;
    }

    /**
     * @param  list<string>  $args
     * @return list<array<string, mixed>>
     */
    private function listWith(array $args, ?string $fields, ?string $status): array
    {
        $args = $this->withFields($args, $fields);
        if ($status !== null) {
            $args[] = '--status='.$status;
        }

        return $this->decodedList($args);
    }

    /**
     * @param  list<string>  $args
     * @return list<array<string, mixed>>
     */
    private function searchWith(array $args, ?string $fields, ?int $perPage): array
    {
        $args = $this->withFields($args, $fields);
        if ($perPage !== null) {
            $args[] = '--per-page='.$perPage;
        }

        return $this->decodedList($args);
    }

    /**
     * @param  list<string>  $args
     * @return list<array<string, mixed>>
     */
    private function decodedList(array $args): array
    {
        $result = $this->cli->runOrFail($args);

        return JsonOutput::decodeList($result['stdout']);
    }

    /**
     * Exit 1 of `is-installed` is "not installed"; any other non-zero exit (a fatal error loading the site) is a failure.
     *
     * @param  list<string>  $args
     */
    private function installedExitCode(array $args): int
    {
        $result = $this->cli->run($args);
        $exitCode = $result['exit_code'];
        if ($exitCode !== 0 && $exitCode !== 1) {
            throw new Exception($this->cli->failureMessage($result));
        }

        return $exitCode;
    }

    /**
     * @param  list<string>  $args
     * @return array<string, string>
     */
    private function mutation(string $slug, array $args): array
    {
        $result = $this->cli->runOrFail($args);

        return $this->mutationBody($slug, $result['stdout']);
    }
}
