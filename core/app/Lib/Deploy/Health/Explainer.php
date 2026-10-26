<?php

namespace App\Lib\Deploy\Health;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Turns a failed check into a diagnosis by reading the project.
 *
 * A check file says what a wrong answer looks like, in four keys of YAML; an
 * explainer says why this project gave one, which needs the files on disk and
 * is therefore code. Explainers run only after their check has failed, so a
 * healthy application never reads a file.
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
