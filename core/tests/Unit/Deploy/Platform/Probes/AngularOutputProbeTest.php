<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\AngularOutputProbe;

/**
 * Where an Angular build actually writes.
 *
 * Angular states its output path inside angular.json under a project name
 * nobody can predict, and Angular 17 split it into `base` plus a `browser`
 * subdirectory. Serving the wrong directory serves an empty one, which looks
 * exactly like a successful deploy until someone opens the site.
 *
 * The probe always matches — the manifest uses it to resolve the path, not
 * to decide ownership — so every case asserts the directory, not a verdict.
 */
class AngularOutputProbeTest extends ProbeTestCase
{
    private function evaluate(): string
    {
        return (new AngularOutputProbe())->evaluate($this->context())['output_directory'];
    }

    public function test_a_declared_output_path_is_used(): void
    {
        $this->writeJson('angular.json', [
            'projects' => [
                'my-app' => ['architect' => ['build' => ['options' => ['outputPath' => 'dist/my-app']]]],
            ],
        ]);

        $this->assertSame('dist/my-app', $this->evaluate());
    }

    public function test_the_newer_targets_key_is_read_too(): void
    {
        $this->writeJson('angular.json', [
            'projects' => [
                'my-app' => ['targets' => ['build' => ['options' => ['outputPath' => 'dist/web']]]],
            ],
        ]);

        $this->assertSame('dist/web', $this->evaluate());
    }

    public function test_an_object_output_path_is_joined_into_a_directory(): void
    {
        // Angular 17+ writes the client bundle under a `browser` subdirectory
        // of `base`; pointing nginx at `base` itself serves the server bundle
        // and a 403.
        $this->writeJson('angular.json', [
            'projects' => [
                'my-app' => [
                    'architect' => [
                        'build' => ['options' => ['outputPath' => ['base' => 'dist/my-app', 'browser' => 'browser']]],
                    ],
                ],
            ],
        ]);

        $this->assertSame('dist/my-app/browser', $this->evaluate());
    }

    public function test_the_application_builder_writes_a_string_output_path_into_browser(): void
    {
        // Angular 17+'s `application` builder puts the client bundle under
        // `<outputPath>/browser` even when outputPath is a plain string
        // (manthanank/expense-tracker-app, Angular 19).
        foreach (['@angular-devkit/build-angular:application', '@angular/build:application'] as $builder) {
            $this->writeJson('angular.json', [
                'projects' => [
                    'expense-tracker-app' => ['architect' => ['build' => [
                        'builder' => $builder,
                        'options' => ['outputPath' => 'dist/expense-tracker-app'],
                    ]]],
                ],
            ]);

            $this->assertSame('dist/expense-tracker-app/browser', $this->evaluate(), $builder);
        }
    }

    public function test_the_legacy_browser_builder_writes_straight_into_output_path(): void
    {
        $this->writeJson('angular.json', [
            'projects' => [
                'my-app' => ['architect' => ['build' => [
                    'builder' => '@angular-devkit/build-angular:browser',
                    'options' => ['outputPath' => 'dist/my-app'],
                ]]],
            ],
        ]);

        $this->assertSame('dist/my-app', $this->evaluate());
    }

    public function test_an_empty_browser_part_flattens_into_base(): void
    {
        $this->writeJson('angular.json', [
            'projects' => [
                'ui' => ['architect' => ['build' => [
                    'builder' => '@angular/build:application',
                    'options' => ['outputPath' => ['base' => 'dist/raw/ui', 'browser' => '']],
                ]]],
            ],
        ]);

        $this->assertSame('dist/raw/ui', $this->evaluate());
    }

    public function test_an_object_output_path_defaults_its_parts(): void
    {
        $this->writeJson('angular.json', [
            'projects' => ['my-app' => ['architect' => ['build' => ['options' => ['outputPath' => []]]]]],
        ]);

        $this->assertSame('dist/browser', $this->evaluate());
    }

    public function test_a_project_without_a_build_target_falls_through_to_one_that_has_it(): void
    {
        // Library projects and e2e projects sit alongside the app in the same
        // file; the first entry is not necessarily the one that builds.
        $this->writeJson('angular.json', [
            'projects' => [
                'my-app-e2e' => ['architect' => ['e2e' => ['options' => []]]],
                'my-app' => ['architect' => ['build' => ['options' => ['outputPath' => 'dist/my-app']]]],
            ],
        ]);

        $this->assertSame('dist/my-app', $this->evaluate());
    }

    public function test_no_angular_json_falls_back_to_dist(): void
    {
        $this->assertSame('dist', $this->evaluate());
    }

    public function test_unreadable_angular_json_falls_back_to_dist(): void
    {
        $this->write('angular.json', 'not json at all {');

        $this->assertSame('dist', $this->evaluate());
    }

    public function test_angular_json_with_no_projects_falls_back_to_dist(): void
    {
        $this->writeJson('angular.json', ['version' => 1]);

        $this->assertSame('dist', $this->evaluate());
    }
}
