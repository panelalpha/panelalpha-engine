<?php

namespace Tests\Unit\Deploy\Platform\Dockerfile;

use App\Lib\Deploy\Platform\Dockerfile\EntrypointInstall;
use App\Lib\Deploy\Platform\Dockerfile\EnvironmentLines;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\StageScript;
use Tests\TestCase;

/**
 * The two smallest pieces every generated Dockerfile is assembled from.
 *
 * EnvironmentLines preserves the caller's ordering, which is how a recipe's
 * own value ends up overriding a generator default; EntrypointInstall is
 * ENTRYPOINT rather than CMD, which is what lets the same image serve an
 * install-once first deploy and a plain restart.
 */
class EnvironmentLinesTest extends TestCase
{
    public function test_each_entry_becomes_an_env_line(): void
    {
        $this->assertSame(
            ['ENV NODE_ENV=production', 'ENV PORT=3000'],
            EnvironmentLines::of(['NODE_ENV' => 'production', 'PORT' => '3000'])
        );
    }

    public function test_the_callers_order_is_preserved(): void
    {
        // Insertion order, not sorted. The generators build their map by
        // merging the recipe's own env over their defaults, and a Dockerfile
        // takes the last ENV for a key - so reordering here would silently
        // flip which of the two wins.
        $env = array_merge(['HOST' => '0.0.0.0', 'PORT' => '3000'], ['HOST' => '::']);

        $this->assertSame(['ENV HOST=::', 'ENV PORT=3000'], EnvironmentLines::of($env));
    }

    public function test_a_plain_value_is_written_bare_as_before(): void
    {
        // Byte-for-byte what the generators wrote before quoting existed, so
        // no existing Dockerfile changes and no layer cache is invalidated.
        $this->assertSame(
            ['ENV PATH=/app/node_modules/.bin:$PATH', 'ENV NODE_OPTIONS=--max-old-space-size=1433', 'ENV EMPTY='],
            EnvironmentLines::of([
                'PATH' => '/app/node_modules/.bin:$PATH',
                'NODE_OPTIONS' => '--max-old-space-size=1433',
                'EMPTY' => '',
            ])
        );
    }

    /**
     * Bare, `ENV JAVA_OPTS=-Xmx256m -XX:+UseSerialGC` fails
     * the build with "can't find = in -XX:+UseSerialGC", and quotes inside a
     * bare value are stripped by the Dockerfile parser. Each expected line was
     * built with docker and read back as the value on the left.
     */
    public function test_a_value_with_spaces_or_quotes_is_quoted_and_survives_the_build(): void
    {
        $this->assertSame(
            [
                'ENV JAVA_OPTS="-Xmx256m -XX:+UseSerialGC"',
                'ENV NODE_CONFIG="{\\"hostname\\":\\"0.0.0.0\\"}"',
                'ENV WIN="C:\\\\app"',
                'ENV GREETING="it\'s # not a comment"',
                'ENV BIN="$HOME/bin dir"',
            ],
            EnvironmentLines::of([
                'JAVA_OPTS' => '-Xmx256m -XX:+UseSerialGC',
                'NODE_CONFIG' => '{"hostname":"0.0.0.0"}',
                'WIN' => 'C:\\app',
                'GREETING' => "it's # not a comment",
                'BIN' => '$HOME/bin dir',
            ])
        );
    }

    public function test_a_build_arg_is_quoted_the_same_way(): void
    {
        $this->assertSame(['ARG OPTS="a b"'], EnvironmentLines::buildArgs(['OPTS' => 'a b']));
    }

    public function test_a_value_on_two_lines_is_refused_rather_than_injected(): void
    {
        // Written bare, the second line is a Dockerfile instruction of its own.
        $this->expectException(\InvalidArgumentException::class);

        EnvironmentLines::of(['X' => "1\nRUN echo injected"]);
    }

    public function test_a_name_that_would_break_the_line_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EnvironmentLines::of(['A=1 B' => '2']);
    }

    public function test_nothing_to_set_produces_no_lines(): void
    {
        $this->assertSame([], EnvironmentLines::of([]));
    }

    public function test_a_build_arg_is_written_for_the_build_and_not_the_image(): void
    {
        // `ARG` reaches every process a `RUN` spawns and is absent from the
        // resulting image's config, which is what a value sized for the build
        // container -- not the account -- has to be.
        $this->assertSame(
            ['ARG NODE_OPTIONS=--max-old-space-size=1433'],
            EnvironmentLines::buildArgs(['NODE_OPTIONS' => '--max-old-space-size=1433'])
        );
    }

    public function test_nothing_to_default_produces_no_build_args(): void
    {
        $this->assertSame([], EnvironmentLines::buildArgs([]));
    }

    public function test_the_entrypoint_is_installed_and_made_executable(): void
    {
        $lines = EntrypointInstall::lines();

        $this->assertStringContainsString('COPY ' . StageScript::FILENAME, $lines);
        $this->assertStringContainsString('RUN chmod +x /' . StageScript::FILENAME, $lines);
    }

    public function test_the_script_is_the_entrypoint_and_not_a_command(): void
    {
        // CMD could only say "run this every time"; the entrypoint branches on
        // the deploy phase, so install-once work does not repeat on a restart.
        // And ENTRYPOINT lets the script's final `exec` make the server PID 1,
        // so `docker stop` reaches it instead of burning the kill timer.
        $lines = EntrypointInstall::lines();

        $this->assertStringContainsString('ENTRYPOINT ["/' . StageScript::FILENAME . '"]', $lines);
        $this->assertStringNotContainsString('CMD', $lines);
    }

    /**
     * The deploy phase never reaches the image.
     *
     * It flips from `install` to `upgrade` on the second deploy of every
     * project, and as an `ENV` above the dependency install it invalidated
     * that layer and everything below it — a Django rebuild of an unchanged
     * commit re-ran `pip install` and cached 1 layer of 10. Compose passes it
     * at runtime, which is where a runtime value belongs.
     */
    public function test_the_deploy_phase_is_not_baked_into_the_image(): void
    {
        $lines = EnvironmentLines::of([
            'HOST' => '0.0.0.0',
            PlatformStage::PHASE_ENV => 'upgrade',
            'PORT' => '8000',
        ]);

        $this->assertSame(['ENV HOST=0.0.0.0', 'ENV PORT=8000'], $lines);
    }
}
