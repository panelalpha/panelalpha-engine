<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;

/**
 * A file called `manage.py` is not evidence of Django.
 *
 * bitcart/bitcart ships exactly one, at `api/views/manage.py`, and it is a
 * FastAPI *router*:
 *
 *     from dishka.integrations.fastapi import DishkaRoute
 *     from fastapi import APIRouter, File, Security, UploadFile
 *     router = APIRouter(route_class=DishkaRoute)
 *
 * Nothing about it is Django, and it does not mention Django once. Detection
 * keyed on the filename claimed the repo as Django anyway, so the deploy ran
 * `migrate` and `runserver` -- and the ASGI application was never started. The
 * build succeeded and the site served nothing.
 *
 * Django's own `manage.py` is generated, and always carries at least one of
 * the same handful of lines: the settings module it configures, the runner it
 * calls, or the import either needs. That is the evidence the search uses now.
 */
class DjangoManagePyEvidenceTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->removeTree($dir);
        }
        $this->dirs = [];

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @param array<string, string> $files relative path => contents */
    private function project(array $files): string
    {
        $dir = sys_get_temp_dir() . '/pa-django-evidence-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        foreach ($files as $name => $contents) {
            $path = $dir . '/' . $name;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }
        $this->dirs[] = $dir;

        return $dir;
    }

    // ---- what Django actually writes ------------------------------------

    /** Django's own template for `django-admin startproject`. */
    private const MANAGE_PY = <<<'PY'
#!/usr/bin/env python
"""Django's command-line utility for administrative tasks."""
import os
import sys


def main():
    """Run administrative tasks."""
    os.environ.setdefault("DJANGO_SETTINGS_MODULE", "proj.settings")
    try:
        from django.core.management import execute_from_command_line
    except ImportError as exc:
        raise ImportError(
            "Couldn't import Django. Are you sure it's installed?"
        ) from exc
    execute_from_command_line(sys.argv)


if __name__ == "__main__":
    main()
PY;

    public function testDjangosOwnManagePyIsDjango(): void
    {
        $dir = $this->project(['manage.py' => self::MANAGE_PY]);

        $this->assertSame('', PythonRuntime::djangoManageDir($dir));
    }

    /** The settings module alone is enough. */
    public function testASettingsModuleAloneIsDjango(): void
    {
        $dir = $this->project([
            'manage.py' => "import os\nos.environ.setdefault('DJANGO_SETTINGS_MODULE', 'proj.settings')\n",
        ]);

        $this->assertSame('', PythonRuntime::djangoManageDir($dir));
    }

    /** And so is the runner alone, which is how some generators write it. */
    public function testTheRunnerAloneIsDjango(): void
    {
        $dir = $this->project([
            'manage.py' => "from django.core.management import execute_from_command_line\nimport sys\nexecute_from_command_line(sys.argv)\n",
        ]);

        $this->assertSame('', PythonRuntime::djangoManageDir($dir));
    }

    /** `import django` in any form, including a bare module import. */
    public function testAnyDjangoImportIsDjango(): void
    {
        foreach ([
            "import django\n",
            "from django.conf import settings\n",
            "from django.core.management import call_command\n",
            "import django # noqa\n",
        ] as $body) {
            $dir = $this->project(['manage.py' => $body]);
            $this->assertSame('', PythonRuntime::djangoManageDir($dir), $body);
        }
    }

    // ---- what is not ------------------------------------------------

    /**
     * bitcart's file, as it really is. The name is the only thing it shares
     * with Django.
     */
    public function testBitcartsFastApiRouterIsNotDjango(): void
    {
        $dir = $this->project([
            'api/views/manage.py' => <<<'PY'
from typing import Any

from dishka import FromDishka
from dishka.integrations.fastapi import DishkaRoute
from fastapi import APIRouter, File, Security, UploadFile

from api import constants, models, utils
from api.constants import AuthScopes
from api.services.backup_manager import BackupManager

router = APIRouter(route_class=DishkaRoute)


@router.get("/policies")
async def get_policies() -> Any:
    return []
PY,
            // bitcart's real shape: no root manage.py, no wsgi.py, no asgi.py.
            'requirements.txt' => "fastapi\nuvicorn\ndishka\n",
        ]);

        $this->assertNull(PythonRuntime::djangoManageDir($dir));
    }

    /** A file that cannot be read is not evidence either. */
    public function testAnEmptyManagePyIsNotDjango(): void
    {
        $dir = $this->project(['manage.py' => '']);

        $this->assertNull(PythonRuntime::djangoManageDir($dir));
    }

    public function testAManagePyWithNoDjangoInItIsNotDjango(): void
    {
        $dir = $this->project(['manage.py' => "print('hello')\n"]);

        $this->assertNull(PythonRuntime::djangoManageDir($dir));
    }

    /** No manage.py at all is the ordinary non-Django case. */
    public function testAProjectWithNoManagePyIsNotDjango(): void
    {
        $dir = $this->project(['app/main.py' => 'from fastapi import FastAPI\n']);

        $this->assertNull(PythonRuntime::djangoManageDir($dir));
    }

    // ---- the two have to agree ----------------------------------------

    /**
     * A decoy in a subdirectory does not stop a real Django project at the
     * root being found, and does not promote a project whose only manage.py is
     * a decoy.
     */
    public function testTheFirstDjangoManagePyWinsAndDecoysAreSkipped(): void
    {
        $dir = $this->project([
            'manage.py' => self::MANAGE_PY,
            'api/views/manage.py' => 'from fastapi import APIRouter\nrouter = APIRouter()\n',
        ]);

        // Root first, and it is Django.
        $this->assertSame('', PythonRuntime::djangoManageDir($dir));
    }

    public function testANestedDjangoManagePyIsFoundPastADecoy(): void
    {
        $dir = $this->project([
            'api/views/manage.py' => 'from fastapi import APIRouter\n',
            'netbox/manage.py' => self::MANAGE_PY,
        ]);

        $this->assertSame('netbox', PythonRuntime::djangoManageDir($dir));
    }

    /**
     * The start command agrees with detection.
     *
     * This is the reason the check lives in `managePyDirs()` rather than in
     * the probe: `manageCommand()` resolves its script through the same walk,
     * so a repo the probe now declines can no longer be handed a
     * `manage.py runserver` command built from a decoy.
     */
    public function testTheStartCommandDoesNotResolveToADecoy(): void
    {
        $dir = $this->project([
            'api/views/manage.py' => 'from fastapi import APIRouter\nrouter = APIRouter()\n',
        ]);

        $this->assertStringNotContainsString('api/views/manage.py', PythonRuntime::manageCommand($dir, 'runserver'));
    }
}
