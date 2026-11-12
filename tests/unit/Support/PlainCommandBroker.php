<?php

namespace Tests\Support;

use App\System\Privileged\Root;

/**
 * A pa-root that hands a test's shim the plain command of each request, and an `account-docker`, `account-container`
 * or `account-compose` request, which runs no command, as `pa-root <request>`. Undo with {@see LocalRoot::answerByBroker()}.
 */
final class PlainCommandBroker
{
    public static function install(string $dir, string $runner): void
    {
        $broker = $dir.'/pa-root';
        file_put_contents($broker, self::script($runner));
        chmod($broker, 0755);
        Root::answerWith($broker);
    }

    private static function script(string $runner): string
    {
        $local = var_export(LocalRoot::client(), true);
        $runner = var_export($runner, true);

        return <<<PY
            #!/usr/bin/env python3
            import json, os, subprocess, sys
            local = {$local}
            runner = {$runner}
            plan = subprocess.run([sys.executable, local, *sys.argv[1:]], env={**os.environ, 'PA_ROOT_LOCAL': 'plan'}, capture_output=True, text=True)
            if plan.returncode != 0:
                sys.stderr.write(plan.stderr)
                sys.exit(plan.returncode)
            argv = json.loads(plan.stdout)['argv']
            if argv is None and sys.argv[1].startswith(('account-docker.', 'account-container.', 'account-compose.')):
                os.execv(runner, [runner, 'pa-root', *sys.argv[1:]])
            if argv is None:
                os.execv(sys.executable, [sys.executable, local, *sys.argv[1:]])
            os.execv(runner, [runner, *argv])
            PY;
    }
}
