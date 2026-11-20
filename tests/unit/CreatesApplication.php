<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    public function createApplication()
    {
        $app = require __DIR__ . '/../../core/bootstrap/app.php';
        $this->useWorkerStorage($app);
        /** @var \App\Console\Kernel $kernel */
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        return $app;
    }

    /** One storage tree per ParaTest worker, so deploy logs and locks of one worker never meet another's. */
    private function useWorkerStorage(Application $app): void
    {
        $worker = getenv('TEST_TOKEN');
        if ($worker === false || $worker === '') {
            return;
        }

        $storage = sys_get_temp_dir() . "/panelalpha-test-storage-worker{$worker}";
        foreach (['app', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
            if (!is_dir("{$storage}/{$dir}")) {
                mkdir("{$storage}/{$dir}", 0775, true);
            }
        }
        $app->useStoragePath($storage);
    }
}
