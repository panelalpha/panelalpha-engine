<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Compose\ServiceAliases;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The names the generated `app` answers to, so a proxy the engine kept can
 * still find the application it was written to front.
 *
 * The file's application service is dropped and replaced by a build under the
 * name `app`; a sibling that reaches it by its old name inside its own nginx
 * config — not through `depends_on`, which is the only reference we can
 * rewrite — dies with `host not found in upstream`. Bolt, CTFd, Offen and
 * Bitpoll all deploy a stack shaped exactly like that.
 */
class ServiceAliasesTest extends TestCase
{
    public function test_the_alias_is_added_to_the_default_network(): void
    {
        $networks = ServiceAliases::withAliases(null, ['php']);

        $this->assertSame(['default' => ['aliases' => ['php']]], $networks);
    }

    /**
     * A list of names and a map of attributes are both valid compose, and the
     * alias lives in the map form — so a list is converted rather than
     * overwritten, or the service would leave the network it declared.
     */
    public function test_a_list_form_keeps_its_networks(): void
    {
        $networks = ServiceAliases::withAliases(['backend', 'frontend'], ['php']);

        $this->assertSame(['backend', 'frontend', 'default'], array_keys($networks));
        $this->assertSame(['aliases' => ['php']], $networks['default']);
    }

    public function test_a_named_network_may_already_declare_aliases(): void
    {
        $networks = ServiceAliases::withAliases(
            ['default' => ['aliases' => ['app']]],
            ['php', 'app']
        );

        $this->assertSame(['app', 'php'], $networks['default']['aliases']);
    }

    public function test_the_apps_own_name_is_never_an_alias(): void
    {
        // Docker resolves the service name already; an alias repeating it is
        // noise, and one that *replaced* it would be worse.
        $this->assertSame([], ServiceAliases::normalised(['app', 'APP', '']));
    }

    public function test_an_unusable_alias_is_dropped_rather_than_written(): void
    {
        // Compose accepts any alias, so a bad one turns a loud start-up
        // failure into a silent one at request time.
        $this->assertSame(
            ['php', 'legacy-app'],
            ServiceAliases::normalised(['php', 'not a host', ':9000', 'legacy-app', 'php'])
        );
    }

    /** A hostname is case-insensitive, so two spellings are one alias. */
    public function test_duplicate_aliases_are_collapsed_case_insensitively(): void
    {
        $this->assertSame(['php'], ServiceAliases::normalised(['php', 'PHP', 'Php']));
    }

    public function test_a_decision_with_no_aliases_contributes_none(): void
    {
        $this->assertSame([], ServiceAliases::fromDecision([]));
        $this->assertSame([], ServiceAliases::fromDecision(['app_aliases' => 'php']));
        $this->assertSame(['php'], ServiceAliases::fromDecision(['app_aliases' => ['php']]));
    }

    /**
     * The whole point, end to end: the file's nginx reaches `php:9000` and the
     * engine's app answers to it.
     */
    public function test_the_generated_app_carries_the_alias(): void
    {
        $yaml = \App\Lib\Deploy\Compose\DeployCompose::dockerfile('Dockerfile', 8000, [
            'app_aliases' => ['php'],
        ]);

        $app = Yaml::parse($yaml)['services'][GeneratedCompose::APP_SERVICE];

        $this->assertSame(['default' => ['aliases' => ['php']]], $app['networks']);
    }

    /** Every generator goes through render(), so every generator gets them. */
    public function test_every_generator_can_carry_an_alias(): void
    {
        $generated = [
            'static' => \App\Lib\Deploy\Compose\DeployCompose::staticNginx(),
            'dockerfile' => \App\Lib\Deploy\Compose\DeployCompose::dockerfile('Dockerfile', 8000, ['app_aliases' => ['php']]),
            'railpack' => \App\Lib\Deploy\Compose\DeployCompose::railpack('acme/app', 8080),
            'framework' => \App\Lib\Deploy\Compose\DeployCompose::framework(
                ['runtime' => 'php', 'image' => 'php:8.3-apache', 'app_aliases' => ['php'], 'env' => []],
                8000,
                null
            ),
        ];

        foreach (['dockerfile', 'framework'] as $name) {
            $app = Yaml::parse($generated[$name])['services'][GeneratedCompose::APP_SERVICE];
            $this->assertSame(
                ['default' => ['aliases' => ['php']]],
                $app['networks'],
                $name
            );
        }
        // And the two that were given nothing keep the shape they had.
        foreach (['static', 'railpack'] as $name) {
            $app = Yaml::parse($generated[$name])['services'][GeneratedCompose::APP_SERVICE];
            $this->assertArrayNotHasKey('networks', $app, $name);
        }
    }
}
