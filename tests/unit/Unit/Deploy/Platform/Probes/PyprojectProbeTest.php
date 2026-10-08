<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\PyprojectProbe;

/**
 * A pyproject.toml is Python evidence only when it declares a project.
 *
 * The name is TOML's generic configuration filename, which every Python
 * ecosystem tool borrows for its own settings. Matching on the name alone
 * made Dolibarr -- a PHP application -- detect as Python and serve the
 * engine's placeholder, and did the same to 52 other repositories in a
 * 1187-app backlog. The predicate is `[project]` (PEP 621) or
 * `[tool.poetry]`, and nothing less.
 */
class PyprojectProbeTest extends ProbeTestCase
{
    private function probe(): PyprojectProbe
    {
        return new PyprojectProbe();
    }

    public function test_a_pep621_project_is_python(): void
    {
        $this->write('pyproject.toml', "[project]\nname = \"fava\"\nversion = \"1.0.0\"\n");

        $this->assertTrue($this->probe()->evaluate($this->context()));
    }

    public function test_a_poetry_project_is_python(): void
    {
        $this->write('pyproject.toml', "[tool.poetry]\nname = \"activitywatch\"\nversion = \"0.14.0\"\n");

        $this->assertTrue($this->probe()->evaluate($this->context()));
    }

    /**
     * Dolibarr's real pyproject.toml, fetched from
     * `Dolibarr/dolibarr@develop`. Its application is PHP; this file exists so
     * `codespell` can be run from the CLI with default options, and it is the
     * whole reason this probe exists.
     *
     * Note the `[build-system]` with `setuptools.build_meta`: a predicate that
     * accepted a Python build backend would still misdetect this exact
     * repository. That is why the build system is not consulted.
     */
    public function test_dolibarrs_codespell_config_is_not_python(): void
    {
        $this->write('pyproject.toml', self::DOLIBARR);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    /**
     * Composio's root pyproject.toml at `ComposioHQ/composio@next`: a uv
     * workspace. The packages live in the `python/` members it lists; the
     * root declares no project of its own.
     */
    public function test_a_uv_workspace_root_is_not_python(): void
    {
        $this->write('pyproject.toml', self::COMPOSIO);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    /** Frigate's is ruff config; the application is an embedded Go/Python image. */
    public function test_ruff_config_is_not_python(): void
    {
        $this->write('pyproject.toml', "[tool.ruff]\ntarget-version = \"py311\"\n\n[tool.ruff.lint]\nignore = [\"E501\", \"E711\"]\n");

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    /**
     * A build-system alone is not a project. It is what a tool that wants
     * `pip install .` for its own editable install leaves behind, and what
     * several non-Python repositories carry.
     */
    public function test_a_bare_build_system_is_not_python(): void
    {
        $this->write('pyproject.toml', "[build-system]\nrequires = [\"setuptools>=61.2\"]\nbuild-backend = \"setuptools.build_meta\"\n");

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_no_pyproject_is_not_python(): void
    {
        $this->write('requirements.txt', "flask\n");

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    /**
     * `[project.scripts]` is a subtable, not the `[project]` table that
     * declares a package. A prefix match would read a scripts-only document
     * as a declared project; it is not one.
     */
    public function test_a_project_subtable_alone_is_not_the_project_table(): void
    {
        $this->write('pyproject.toml', "[project.scripts]\nfava = \"fava.cli:main\"\n");

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    /** A table header may carry a trailing comment: `[project]  # the app`. */
    public function test_a_commented_table_header_still_declares_the_project(): void
    {
        $this->write('pyproject.toml', "[project]  # the application\nname = \"app\"\n");

        $this->assertTrue($this->probe()->evaluate($this->context()));
    }

    /**
     * Dolibarr/dolibarr @ develop, verbatim. The file is longer than most of
     * the values in this suite because it is the evidence: it carries five
     * `[tool.*]` tables and a `[build-system]`, and not one of them says the
     * application is Python.
     */
    private const DOLIBARR = <<<'TOML'
[build-system]
requires = ["setuptools>=61.2"]
build-backend = "setuptools.build_meta"

[tool.codespell]
# The configuration must be kept here to ensure that
# `codespell` can be run as a standalone program from the CLI
# with the appropriate default options.

skip = "*/.*/*,*/langs/*,*/dev/build/exe/*,**.log,*.pdf,*.PDF,*dev/resources/*,*.phar,*.z,*.gz,*.sql,*.svg,*htdocs/includes/*,*/textiso.txt,*.js,*README-*,*build/rpm/*spec,*build/pad/*ml,*htdocs/includes/phpoffice/*,*htdocs/includes/tecnickcom/*,*dev/initdemo/removeconfdemo.sh,*dev/tools/codespell/*,*dev/trans*/ignore_translation_keys.lst,*pyproject.toml,*build/exe/*,*fontawe*,*htdocs/theme/*/flags-sprite.inc.php,*dev/setup/codetemplates/codetemplates.xml,*/php.ini,*/html_cerfafr.*,*/lessc.class.php,*.asciidoc,*.xml,*opensurvey/css/style.css,*dev/tools/phan/stubs/*,*/documents,phpstan.*,*dev/initdemo/documents_demo/blockedlog/archives/*"

check-hidden = true
quiet-level=2
ignore-regex = '\\[fnrstv]'
builtin = "clear,rare,informal,usage,code,names"

ignore-words = "dev/tools/codespell/codespell-ignore.txt"
exclude-file = "dev/tools/codespell/codespell-lines-ignore.txt"
uri-ignore-words-list="ned"

[tool.setuptools]
include-package-data = false

[tool.yamlfix]
line_length = 80

[tool.sqlfluff.core]
ignore_comment_lines = true
sql_file_exts = ".sql"
encoding = "utf-8"
processes = -1
exclude_rules = "LT01,LT02,LT05,LT12,LT13,LT14,LT15,CP01,CP02,CP04,CP05,RF04"
dialect = "mysql"
large_file_skip_byte_limit = 100000

[tool.sqlfluff.indentation]
indent_unit = "tab"
TOML;

    /** ComposioHQ/composio @ next, the root workspace manifest. */
    private const COMPOSIO = <<<'TOML'
[tool.uv.workspace]
members = [
    "python",
    "python/providers/anthropic",
    "python/providers/openai",
]

[tool.uv.sources]

composio = { workspace = true }

[dependency-groups]
dev = [
    "composio==1.0.0rc6",
]
TOML;
}
