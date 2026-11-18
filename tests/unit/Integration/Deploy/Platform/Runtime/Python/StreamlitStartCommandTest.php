<?php

namespace Tests\Integration\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * A Streamlit app, started by `streamlit run <script>`.
 *
 * opengeos/streamlit-geospatial (`Home.py`), goku-open/goku-image-to-svg-tool (`streamlit_app.py`) and
 * chnjames/AIGEOTOOLS (`geo_tool.py`) served the placeholder with "No start command could be worked out",
 * and a Streamlit `app.py` was run as `python app.py`, which runs the page once in bare mode and exits.
 */
class StreamlitStartCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-pystreamlit-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->dir.'/'.$name, $contents);
    }

    private function start(string $requirements): string
    {
        $this->write('requirements.txt', $requirements);

        return PythonRuntime::startCommand($this->dir, [$requirements]);
    }

    private function streamlit(string $script): string
    {
        return '.venv/bin/streamlit run '.$script.' --server.address '.TestNetwork::ANY.' --server.port 8000 --server.headless true';
    }

    public function test_the_one_root_script_importing_streamlit_is_run_by_streamlit(): void
    {
        $this->write('Home.py', "import streamlit as st\n\nst.title('Home')\n");
        $this->write('config.py', "KEY = 1\n");

        $this->assertSame($this->streamlit('Home.py'), $this->start("streamlit>=1.30\nleafmap\n"));
    }

    public function test_streamlit_app_py_wins_over_helpers_importing_streamlit(): void
    {
        $this->write('streamlit_app.py', "import streamlit as st\n");
        $this->write('i18n.py', "import streamlit as st\n");
        $this->write('utils.py', "from streamlit import session_state\n");

        $this->assertSame($this->streamlit('streamlit_app.py'), $this->start("streamlit\n"));
    }

    public function test_a_streamlit_app_py_is_not_run_as_a_plain_script(): void
    {
        $this->write('app.py', "import streamlit as st\n\nst.write('hi')\n");

        $this->assertSame($this->streamlit('app.py'), $this->start("streamlit\npandas\n"));
    }

    public function test_two_streamlit_scripts_are_not_guessed_between(): void
    {
        $this->write('app_chat.py', "import streamlit as st\n");
        $this->write('app_upload.py', "import streamlit as st\n");

        $this->assertStringContainsString(PythonRuntime::PLACEHOLDER_DIR, $this->start("streamlit\n"));
    }

    public function test_a_script_importing_streamlit_without_it_declared_is_left_alone(): void
    {
        $this->write('app.py', "import streamlit as st\n");

        $this->assertSame('.venv/bin/python app.py', $this->start("pandas\n"));
    }

    public function test_a_declared_server_with_a_root_application_still_comes_first(): void
    {
        $this->write('app.py', "from fastapi import FastAPI\n\napp = FastAPI()\n");
        $this->write('dashboard.py', "import streamlit as st\n");

        $this->assertSame(
            '.venv/bin/uvicorn app:app --host ' . TestNetwork::ANY . ' --port 8000',
            $this->start("fastapi\nuvicorn\nstreamlit\n")
        );
    }
}
