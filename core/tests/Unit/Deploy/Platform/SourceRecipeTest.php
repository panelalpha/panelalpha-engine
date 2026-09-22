<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * Recipes found by the URL a project was cloned from, rather than by reading
 * the checkout.
 *
 * The tree is addressed by path, which makes two things worth pinning: that
 * every spelling of one remote reaches the same file (in
 * {@see \Tests\Unit\Deploy\Source\RepoUrlTest}), and that a file which is
 * found is understood the same way a shipped manifest would be.
 */
class SourceRecipeTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/pa-source-recipe-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
        SourceRecipes::flush();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        SourceRecipes::flush();
        parent::tearDown();
    }

    public function test_a_repository_with_no_recipe_is_not_an_error(): void
    {
        $this->assertNull(SourceRecipes::for('https://github.com/acme/widget', $this->tmpDir));
    }

    /**
     * The engine's own tree is written by us, so a directory that forgot its
     * config is a mistake to report rather than a repository that chose to say
     * nothing.
     */
    public function test_a_directory_without_a_config_is_refused(): void
    {
        mkdir($this->tmpDir . '/github.com/acme/widget', 0777, true);
        file_put_contents($this->tmpDir . '/github.com/acme/widget/notes.md', "hello\n");

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage(AppConfigDirectory::CONFIG . ' is required');
        SourceRecipes::for('https://github.com/acme/widget', $this->tmpDir);
    }

    public function test_an_archive_upload_has_no_source_to_look_up(): void
    {
        $this->assertNull(SourceRecipes::for(null, $this->tmpDir));
        $this->assertNull(SourceRecipes::for('', $this->tmpDir));
    }

    public function test_a_standalone_recipe_needs_neither_detect_nor_priority(): void
    {
        $this->write("id: widget\nlabel: Widget\nruntime: node\nport: 4000\n");

        $recipe = SourceRecipes::for('https://github.com/acme/widget.git', $this->tmpDir);

        $this->assertNotNull($recipe);
        $this->assertSame('widget', $recipe->id);
        $this->assertSame(4000, $recipe->port);
        // Empty, and therefore unmatchable: nothing in the priority walk can
        // reach a recipe that is only ever found by its path.
        $this->assertSame([], $recipe->detect);
    }

    public function test_naming_a_shipped_recipe_inherits_all_of_it(): void
    {
        $this->write("extends: matomo\n");

        $recipe = SourceRecipes::for('https://github.com/acme/widget', $this->tmpDir);
        $shipped = PlatformRegistry::find('matomo');

        $this->assertNotNull($recipe);
        $this->assertNotNull($shipped);
        $this->assertSame($shipped->id, $recipe->id);
        $this->assertSame($shipped->runtime, $recipe->runtime);
        $this->assertSame($shipped->database, $recipe->database);
        $this->assertSame(count($shipped->commands), count($recipe->commands));
    }

    public function test_a_fork_overrides_only_what_it_names(): void
    {
        $this->write("extends: matomo\nlabel: Widget's Matomo\nport: 9000\n");

        $recipe = SourceRecipes::for('https://github.com/acme/widget', $this->tmpDir);

        $this->assertNotNull($recipe);
        $this->assertSame("Widget's Matomo", $recipe->label);
        $this->assertSame(9000, $recipe->port);
        $this->assertSame('mysql', $recipe->database);
    }

    public function test_naming_a_recipe_that_does_not_exist_says_so(): void
    {
        $this->write("extends: no-such-recipe\n");

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("nothing extends 'no-such-recipe'");
        SourceRecipes::for('https://github.com/acme/widget', $this->tmpDir);
    }

    /**
     * The path chose this recipe. A `detect` block would sit in the file
     * looking like it had a say and never be evaluated, so it is refused
     * rather than dropped.
     */
    public function test_a_detect_block_is_refused(): void
    {
        $this->write("id: widget\nlabel: Widget\ndetect:\n  file: package.json\n");

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("'detect' has no meaning here");
        SourceRecipes::for('https://github.com/acme/widget', $this->tmpDir);
    }

    public function test_a_malformed_recipe_is_refused(): void
    {
        $this->write("extends: matomo\n  bad indent:\n");

        $this->expectException(ManifestException::class);
        SourceRecipes::for('https://github.com/acme/widget', $this->tmpDir);
    }

    /**
     * A deploy records the recipe that claimed it as an id and resolves the
     * manifest back through the registry on every read afterwards.
     */
    public function test_the_registry_resolves_a_source_recipe_by_its_id(): void
    {
        $this->assertNotNull(PlatformRegistry::find('matomo'));
    }

    /**
     * `build_args` is the only way to reach a Dockerfile variant selected by
     * an ARG — Kimai's `FROM ${BASE}-base` stages. It has to survive the
     * extends merge and reach the decision the compose writer reads.
     */
    public function test_build_args_are_read_and_carried_into_the_decision(): void
    {
        $this->write("extends: dockerfile\nbuild_args:\n  BASE: apache\n");

        $recipe = SourceRecipes::for('https://github.com/acme/widget', $this->tmpDir);

        $this->assertNotNull($recipe);
        $this->assertSame(['BASE' => 'apache'], $recipe->buildArgs);
        $this->assertSame(['BASE' => 'apache'], $recipe->describe($this->context())['build_args']);
    }

    /**
     * A name that is not an ARG name is a typo that would build the wrong
     * variant silently, and a value that is not a scalar is one nobody can
     * pass to `docker build`.
     */
    public function test_a_malformed_build_arg_is_refused(): void
    {
        $this->write("extends: dockerfile\nbuild_args:\n  'not a name': apache\n");

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('not a valid Dockerfile build-arg name');
        SourceRecipes::for('https://github.com/acme/widget', $this->tmpDir);
    }

    /**
     * A repository that ships no Dockerfile of its own asks for no args. An
     * empty map must not become a claim, and must not reach the compose file
     * as `build: {context: ., args: {}}`.
     */
    public function test_a_recipe_without_build_args_declares_none(): void
    {
        $this->write("extends: dockerfile\n");

        $recipe = SourceRecipes::for('https://github.com/acme/widget', $this->tmpDir);

        $this->assertNotNull($recipe);
        $this->assertSame([], $recipe->buildArgs);
    }

    private function context(): \App\Lib\Deploy\Platform\ProjectContext
    {
        return \App\Lib\Deploy\Platform\ProjectContext::make(
            $this->tmpDir,
            \App\Lib\Deploy\Platform\ProjectContext::listRootFiles($this->tmpDir)
        );
    }

    /** Every recipe in resources/sources/ parses, validates and inherits. */
    public function test_the_shipped_tree_loads(): void
    {
        $recipes = SourceRecipes::all();

        // `all()` skips what it cannot read so that one malformed directory
        // does not take every app on a live host with it. Here, where the tree
        // is the shipped one, a skip is a defect: this is the strict pass.
        $this->assertSame(
            [],
            SourceRecipes::skipped(),
            'a shipped recipe directory could not be read'
        );
        $this->assertNotEmpty($recipes, 'resources/sources/ shipped no recipes');
        foreach ($recipes as $recipe) {
            $this->assertNotSame('', $recipe->id);
            $this->assertNotSame('', $recipe->label);
            $this->assertSame([], $recipe->detect);
        }
    }

    /**
     * An id is what a deploy persists and what every later read resolves the
     * manifest back through, so two recipes sharing one must be the same
     * stack. Inheriting `app: matomo` deliberately keeps the id it inherited
     * — that is how the decision stays resolvable — and what must not happen
     * is a source recipe answering to an id that already means something
     * else.
     *
     * Sharing an id on purpose is the other case, and it is legal: a recipe
     * with its own `id` plus `extends` names a stack several repositories are
     * instances of (the ladigitale SPA+PHP family, five repositories, one
     * `ladigitale-spa-php`). What may not differ is the *stack* — strategy
     * and runtime — so the walk now deduplicates by id and asserts that every
     * recipe answering to one id agrees on both, instead of refusing the
     * sharing outright.
     */
    public function test_no_shipped_source_recipe_shadows_a_different_stack(): void
    {
        /** @var array<string, PlatformManifest> $seen */
        $seen = [];
        foreach (SourceRecipes::all() as $recipe) {
            $first = $seen[$recipe->id] ?? null;
            if ($first !== null) {
                $this->assertSame(
                    $first->strategy,
                    $recipe->strategy,
                    "two source recipes claim id '{$recipe->id}' with different stacks: "
                    . "{$first->source} vs {$recipe->source}"
                );
                $this->assertSame(
                    $first->runtime,
                    $recipe->runtime,
                    "two source recipes claim id '{$recipe->id}' with different runtimes"
                );

                continue;
            }
            $seen[$recipe->id] = $recipe;

            $shipped = PlatformRegistry::find($recipe->id);
            if ($shipped !== null && $shipped->source !== $recipe->source) {
                $this->assertSame(
                    $shipped->strategy,
                    $recipe->strategy,
                    "source recipe {$recipe->source} answers to id '{$recipe->id}', which is a different stack"
                );
            }
        }
    }

    /**
     * An app config is a manifest with extra keys, and the schema an editor
     * validates it against has to say so: every manifest property, spelled the
     * way the manifest spells it, or the two drift and only one of them is
     * checked.
     */
    public function test_the_app_config_schema_carries_every_manifest_key(): void
    {
        $manifest = $this->schema('platforms/_schema.json');
        $config = $this->schema('sources/_schema.json');

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($manifest['properties']), array_keys($config['properties']))),
            'the app config schema is missing manifest keys'
        );
        $this->assertSame($manifest['definitions'], $config['definitions']);
        // Nothing is required: a config that only names hooks describes no
        // manifest, and `detect` is refused rather than merely unused.
        $this->assertArrayNotHasKey('required', $config);
        // And every key may be written with no value at all.
        foreach ($config['properties'] as $key => $property) {
            if (in_array($key, ['$schema', 'detect'], true)) {
                continue;
            }
            $this->assertContains(
                ['type' => 'null'],
                $property['anyOf'] ?? [],
                "'{$key}' cannot be left empty"
            );
        }
        $this->assertSame(['not' => [], 'description' => $config['properties']['detect']['description']], $config['properties']['detect']);
    }

    /** What the parser accepts and what the schema declares are one list. */
    public function test_the_schema_and_the_parser_agree_on_the_keys(): void
    {
        $schema = array_keys($this->schema('sources/_schema.json')['properties']);
        $parser = AppConfig::knownKeys();
        sort($schema);
        sort($parser);

        $this->assertSame($schema, $parser);
    }

    /** @return array<string, mixed> */
    private function schema(string $relative): array
    {
        $path = dirname(__DIR__, 4) . '/resources/' . $relative;
        $this->assertFileExists($path);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * A key written with no value says nothing. Under `extends` it must leave
     * the recipe's own answer alone: `port:` on a fork of Matomo is not a
     * request to serve on no port at all.
     */
    public function test_an_empty_key_inherits_rather_than_unsets(): void
    {
        $this->write("extends: matomo\nport:\ndatabase:\npriority:\n");

        $recipe = SourceRecipes::for('https://github.com/acme/widget', $this->tmpDir);
        $shipped = PlatformRegistry::find('matomo');

        $this->assertNotNull($recipe);
        $this->assertNotNull($shipped);
        $this->assertSame($shipped->port, $recipe->port);
        $this->assertSame($shipped->database, $recipe->database);
        $this->assertSame($shipped->priority, $recipe->priority);
    }

    /** A value that is actually stated still overrides it. */
    public function test_a_stated_key_still_overrides_what_it_extends(): void
    {
        $this->write("extends: matomo\nport: 9000\n");

        $recipe = SourceRecipes::for('https://github.com/acme/widget', $this->tmpDir);

        $this->assertNotNull($recipe);
        $this->assertSame(9000, $recipe->port);
    }

    /** Shipped directories live at exactly <host>/<owner>/<repo>, never deeper. */
    public function test_the_shipped_tree_is_addressed_by_url(): void
    {
        $slugs = array_keys(SourceRecipes::directories());

        $this->assertNotEmpty($slugs);
        foreach ($slugs as $slug) {
            $this->assertCount(3, explode('/', $slug), "{$slug} is not <host>/<owner>/<repo>");
        }
    }

    /**
     * The URL outranks the files: an otherwise plain PHP checkout deploys as
     * Matomo when it was cloned from Matomo, and as plain PHP when it was
     * not.
     */
    public function test_the_source_recipe_wins_over_file_detection(): void
    {
        $project = $this->tmpDir . '/project';
        mkdir($project, 0777, true);
        file_put_contents(
            $project . '/composer.json',
            (string) json_encode(['require' => ['php' => '>=8.2']])
        );

        $byFiles = DetectProjectStrategy::detect($project);
        $bySource = DetectProjectStrategy::detect($project, 'https://github.com/matomo-org/matomo');

        $this->assertSame('php', $byFiles['platform']);
        $this->assertSame('matomo', $bySource['platform']);
        $this->assertSame('mysql', $bySource['database']);
        $this->assertSame('github.com/matomo-org/matomo', $bySource['source_recipe']);
    }

    /**
     * A repository that describes its own hosting knows more than a page
     * written about it from outside, so its own directory outranks the
     * engine's — and both outrank the file rules.
     */
    public function test_a_projects_own_panelalpha_directory_names_its_platform(): void
    {
        $project = $this->tmpDir . '/own';
        mkdir($project . '/' . AppConfigDirectory::DIRNAME, 0777, true);
        file_put_contents(
            $project . '/composer.json',
            (string) json_encode(['require' => ['php' => '>=8.2']])
        );
        file_put_contents(
            $project . '/' . AppConfigDirectory::DIRNAME . '/' . AppConfigDirectory::CONFIG,
            "extends: matomo\n"
        );

        $decision = DetectProjectStrategy::detect($project, 'https://github.com/acme/fork');

        $this->assertSame('matomo', $decision['platform']);
        $this->assertSame(AppConfigDirectory::DIRNAME, $decision['source_recipe']);
    }

    private function write(string $contents): void
    {
        @mkdir($this->tmpDir . '/github.com/acme/widget', 0777, true);
        file_put_contents(
            $this->tmpDir . '/github.com/acme/widget/' . AppConfigDirectory::CONFIG,
            $contents
        );
        SourceRecipes::flush();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
