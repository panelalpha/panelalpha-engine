<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Platform\Metadata\AppPackage;
use App\Lib\Deploy\Platform\Metadata\Framework;
use App\Lib\Deploy\Telemetry\AppFacts;
use App\Lib\Deploy\Telemetry\DeployReport;
use PHPUnit\Framework\TestCase;

class AppFactsTest extends TestCase
{
    private const INSTALL = 'install-abc';

    /**
     * @param array<string, mixed> $overrides
     */
    private function composer(array $overrides = []): AppPackage
    {
        return new AppPackage(
            ecosystem: $overrides['ecosystem'] ?? 'php',
            file: 'composer.json',
            name: $overrides['name'] ?? 'acme/shop',
            nameSource: $overrides['nameSource'] ?? 'composer.json name',
            description: 'The customer says what their app is here',
            version: $overrides['version'] ?? '2.4.1',
            license: 'MIT',
            homepage: 'https://shop.example.com',
            repository: 'https://github.com/acme/shop',
            authors: ['Someone <someone@example.com>'],
            keywords: ['shop'],
            private: true,
            scripts: ['post-install-cmd'],
            entrypoints: ['bin/console'],
            workspaces: [],
            frameworks: $overrides['frameworks'] ?? [
                new Framework('laravel', 'Laravel', '^11.0', '11.9.2', 'composer.lock'),
            ],
            dependencyCounts: ['require' => 34, 'require-dev' => 12],
            platform: $overrides['platform'] ?? ['ext-gd' => '*', 'ext-intl' => '*', 'php' => '^8.2']
        );
    }

    public function test_reports_the_stack_without_the_customers_own_strings(): void
    {
        $app = AppFacts::build([$this->composer()], DeployReport::TIER_LOG, false, self::INSTALL);

        $this->assertSame('php', $app['ecosystem']);
        $this->assertSame('composer.json', $app['file']);
        $this->assertTrue($app['named']);
        $this->assertSame(
            [[
                'id' => 'laravel',
                'name' => 'Laravel',
                'constraint' => '^11.0',
                'version' => '11.9.2',
                'major' => 11,
            ]],
            $app['frameworks']
        );
        $this->assertSame(['php' => '^8.2', 'ext-gd' => '*', 'ext-intl' => '*'], $app['platform']);
        $this->assertSame(['require' => 34, 'require-dev' => 12], $app['dependencies']);

        // Everything the reader knows and a report may not say.
        foreach (['description', 'authors', 'keywords', 'homepage', 'repository', 'license', 'scripts'] as $field) {
            $this->assertArrayNotHasKey($field, $app);
        }
    }

    public function test_names_a_public_application_and_hashes_a_private_one(): void
    {
        $public = AppFacts::build([$this->composer()], DeployReport::TIER_REPO, false, self::INSTALL);
        $this->assertSame('acme/shop', $public['name']);
        $this->assertSame('2.4.1', $public['version']);
        $this->assertArrayNotHasKey('name_hash', $public);

        $private = AppFacts::build([$this->composer()], DeployReport::TIER_REPO, true, self::INSTALL);
        $this->assertArrayNotHasKey('name', $private);
        $this->assertArrayNotHasKey('version', $private);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $private['name_hash']);
    }

    public function test_the_private_hash_groups_within_an_install_and_not_across_them(): void
    {
        $here = AppFacts::build([$this->composer()], DeployReport::TIER_REPO, true, self::INSTALL);
        $again = AppFacts::build([$this->composer()], DeployReport::TIER_REPO, true, self::INSTALL);
        $elsewhere = AppFacts::build([$this->composer()], DeployReport::TIER_REPO, true, 'install-xyz');

        $this->assertSame($here['name_hash'], $again['name_hash']);
        $this->assertNotSame($here['name_hash'], $elsewhere['name_hash']);
    }

    public function test_tier_zero_carries_the_stack_and_no_identity_at_all(): void
    {
        $app = AppFacts::build([$this->composer()], DeployReport::TIER_METADATA, false, self::INSTALL);

        $this->assertSame('laravel', $app['frameworks'][0]['id']);
        $this->assertSame('^8.2', $app['platform']['php']);
        $this->assertArrayNotHasKey('name', $app);
        $this->assertArrayNotHasKey('name_hash', $app);
        $this->assertArrayNotHasKey('version', $app);
    }

    public function test_a_directory_name_is_never_reported_as_the_applications_name(): void
    {
        $package = $this->composer([
            'name' => 'project',
            'nameSource' => AppPackage::NAME_FROM_DIRECTORY,
        ]);

        $public = AppFacts::build([$package], DeployReport::TIER_REPO, false, self::INSTALL);
        $private = AppFacts::build([$package], DeployReport::TIER_REPO, true, self::INSTALL);

        $this->assertFalse($public['named']);
        $this->assertArrayNotHasKey('name', $public);
        $this->assertArrayNotHasKey('name_hash', $private);
    }

    public function test_keeps_every_runtime_constraint_and_caps_the_extension_list(): void
    {
        $platform = ['php' => '^8.3'];
        foreach (range(1, AppFacts::MAX_EXTENSIONS + 10) as $i) {
            $platform[sprintf('ext-%02d', $i)] = '*';
        }

        $app = AppFacts::build(
            [$this->composer(['platform' => $platform])],
            DeployReport::TIER_LOG,
            false,
            self::INSTALL
        );

        $this->assertSame('^8.3', $app['platform']['php']);
        $this->assertCount(AppFacts::MAX_EXTENSIONS + 1, $app['platform']);
    }

    public function test_names_the_other_ecosystems_of_a_polyglot_project(): void
    {
        $app = AppFacts::build(
            [
                $this->composer(),
                $this->composer(['ecosystem' => 'node']),
                $this->composer(['ecosystem' => 'python']),
            ],
            DeployReport::TIER_LOG,
            false,
            self::INSTALL
        );

        $this->assertSame(['node', 'python'], $app['also']);
    }

    public function test_caps_the_framework_list(): void
    {
        $frameworks = [];
        foreach (range(1, AppFacts::MAX_FRAMEWORKS + 3) as $i) {
            $frameworks[] = new Framework("fw{$i}", "FW{$i}", '^1.0', '1.0.0', 'composer.json');
        }

        $app = AppFacts::build(
            [$this->composer(['frameworks' => $frameworks])],
            DeployReport::TIER_LOG,
            false,
            self::INSTALL
        );

        $this->assertCount(AppFacts::MAX_FRAMEWORKS, $app['frameworks']);
    }

    public function test_an_empty_map_is_omitted_rather_than_sent_as_an_empty_array(): void
    {
        // A package.json with no `engines` and no counted sections: PHP would
        // encode both as `[]`, which is a JSON array where the ingest expects
        // an object.
        $bare = new AppPackage(
            ecosystem: 'node',
            file: 'package.json',
            name: 'car-coop',
            nameSource: 'package.json name'
        );

        $app = AppFacts::build([$bare], DeployReport::TIER_LOG, false, self::INSTALL);

        $this->assertArrayNotHasKey('platform', $app);
        $this->assertArrayNotHasKey('dependencies', $app);
        // A list stays a list — `[]` is the correct encoding for it.
        $this->assertSame([], $app['frameworks']);
        $this->assertSame('car-coop', $app['name']);

        $json = json_encode($app);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('"platform":[]', $json);
    }

    public function test_a_project_no_reader_recognises_reports_nothing(): void
    {
        $this->assertSame([], AppFacts::build([], DeployReport::TIER_LOG, false, self::INSTALL));
    }
}
