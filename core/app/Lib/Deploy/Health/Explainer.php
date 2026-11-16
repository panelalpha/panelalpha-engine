<?php

namespace App\Lib\Deploy\Health;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Turns a failed check into a diagnosis by reading the project's files, which a YAML check file cannot;
 * it runs only after its check failed, so a healthy app never reads a file.
 */
interface Explainer
{
    /** The name a check file refers to this by. */
    public function id(): string;

    /**
     * @return array{detail?: ?string, fix?: ?string} what to say instead of
     *                                                the check file's own `fix`, as far as this project can be read
     */
    public function explain(ProjectContext $context, ProbedResponse $response): array;
}
