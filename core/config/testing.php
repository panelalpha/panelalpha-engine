<?php

use App\System\EnginePaths;

return [
    /*
     * Runtime test coverage (App\Lib\Testing\CoverageRecorder, `testing:coverage`).
     *
     * `coverage_dir` is where core reads and writes recordings and dumps, as seen
     * from inside the core container: the engine's tests/coverage directory,
     * bind-mounted at /var/www/tests/coverage (docker-compose.yml), beside the
     * ./core mount at /var/www/html. `coverage_host_dir` is the same directory
     * on the host, for containers core starts through the host Docker daemon.
     */
    'coverage_dir' => dirname(base_path()) . '/tests/coverage',
    'coverage_host_dir' => EnginePaths::ENGINE_DIR . '/tests/coverage',
];
