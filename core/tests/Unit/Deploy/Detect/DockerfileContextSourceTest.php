<?php

namespace Tests\Unit\Deploy\Detect;

use App\Lib\Deploy\Detect\DockerfileFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A Dockerfile that copies from a path the checkout does not contain cannot be
 * built, and says so before an account is provisioned for it.
 *
 * The three named cases are real repositories that took a deploy down each.
 */
final class DockerfileContextSourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pa-dockerfile-ctx-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testGoToSocialCopiesAGitignoredGeneratedFile(): void
    {
        // codeberg.org/superseriousbusiness/gotosocial: .gitignore excludes
        // web/assets/swagger.yaml, which goreleaser writes before docker runs.
        $this->assertSame(
            'web/assets/swagger.yaml',
            DockerfileFinder::missingContextSource(
                "FROM alpine\nCOPY web/assets/swagger.yaml /gotosocial/web/assets/\n",
                $this->dir
            )
        );
    }

    public function testShioriCopiesAGoReleaserDistPath(): void
    {
        $this->assertSame(
            'dist/shiori_${TARGETOS}_${TARGETARCH}${TARGETVARIANT}/shiori',
            DockerfileFinder::missingContextSource(
                "FROM alpine\nCOPY dist/shiori_\${TARGETOS}_\${TARGETARCH}\${TARGETVARIANT}/shiori /usr/bin/shiori\n",
                $this->dir
            )
        );
    }

    public function testFusionCopiesABuildDirectoryItsDockerignoreExcludes(): void
    {
        $this->assertSame(
            'build/fusion-${TARGETOS}-${TARGETARCH}',
            DockerfileFinder::missingContextSource(
                "FROM alpine:3.21.0\n"
                . "COPY --chown=fusion:fusion --chmod=755 build/fusion-\${TARGETOS}-\${TARGETARCH} ./fusion\n",
                $this->dir
            )
        );
    }

    /** The literal prefix is what is checked, so a present directory passes. */
    public function testAVariablePathUnderAPresentDirectoryIsAccepted(): void
    {
        mkdir($this->dir . '/dist');

        $this->assertNull(DockerfileFinder::missingContextSource(
            "FROM alpine\nCOPY dist/app-\${VERSION}/app /usr/bin/app\n",
            $this->dir
        ));
    }

    public function testBichonCopiesACiBuiltBinaryUnderAPlatformDirectory(): void
    {
        // github.com/rustmailer/bichon: docker/Dockerfile packages binaries the
        // release job leaves in amd64/ and arm64/, which a clone does not have.
        $this->assertSame(
            '${TARGETARCH}/bichon-server',
            DockerfileFinder::missingContextSource(
                "FROM ubuntu:24.04\nARG TARGETARCH\nCOPY \${TARGETARCH}/bichon-server /opt/bichon/bichon-server\n",
                $this->dir
            )
        );
    }

    /**
     * github.com/halo-dev/halo: the jar comes from a build arg's default, which
     * nothing overrides, and Gradle has not run. BuildKit failed with
     * `lstat /application/build/libs: no such file or directory`.
     */
    public function testHaloCopiesAJarNamedByABuildArgDefault(): void
    {
        $this->assertSame('${JAR_FILE}', DockerfileFinder::missingContextSource(
            "FROM eclipse-temurin:21-jre AS builder\nWORKDIR /application\n"
            . "ARG JAR_FILE=/application/build/libs/*.jar\nCOPY \${JAR_FILE} application.jar\n",
            $this->dir
        ));
    }

    /** github.com/bayang/jelu: `ARG DEPENDENCY=build/dependency`, a layertools extract. */
    public function testJeluCopiesALayeredJarExtractNamedByABuildArg(): void
    {
        $this->assertSame('${DEPENDENCY}/dependencies/', DockerfileFinder::missingContextSource(
            "FROM eclipse-temurin:17-jre-noble\nARG DEPENDENCY=build/dependency\n"
            . "COPY \${DEPENDENCY}/dependencies/ ./\nCOPY \${DEPENDENCY}/application/jelu-*.jar ./jelu.jar\n",
            $this->dir
        ));
    }

    public function testABuildArgDefaultThatExistsIsAccepted(): void
    {
        mkdir($this->dir . '/src');

        $this->assertNull(DockerfileFinder::missingContextSource(
            "FROM alpine\nARG SRC=src\nENV DEST=/app\nCOPY \$SRC \$DEST\n",
            $this->dir
        ));
    }

    /** With the packaging Dockerfile out of the way, the Gradle build is what deploys. */
    public function testAPackagingDockerfileLeavesNoDockerfileCandidate(): void
    {
        file_put_contents($this->dir . '/Dockerfile', "FROM eclipse-temurin:21-jre\n"
            . "ARG JAR_FILE=build/libs/*.jar\nCOPY \${JAR_FILE} app.jar\n");

        $this->assertNull(DockerfileFinder::find($this->dir, ['dockerfile' => true]));
    }

    public function testSlskdCopiesAHeredocNotAContextFile(): void
    {
        // github.com/slskd/slskd: `COPY <<'SCRIPT'` is inline content, and the
        // body's own lines are not instructions.
        mkdir($this->dir . '/bin');

        $this->assertNull(DockerfileFinder::missingContextSource(
            "FROM alpine\nCOPY bin bin/.\nCOPY <<'SCRIPT' /entrypoint.sh\n#!/bin/sh\n"
            . "COPY missing /nowhere\nexec app\nSCRIPT\nRUN <<EOF\nADD gone /x\nEOF\n",
            $this->dir
        ));
    }

    /** slskd's PUID/PGID handling is a runtime entrypoint, not a host-UID build. */
    public function testAHeredocEntrypointReadingPuidIsStillADockerfile(): void
    {
        file_put_contents($this->dir . '/Dockerfile', "FROM debian\nRUN useradd -o -u 1000 slskd\n"
            . "COPY <<'SCRIPT' /entrypoint.sh\nusermod -o -u \"\$PUID\" slskd\nexec gosu \"\${PUID}\" \"\$@\"\nSCRIPT\n");

        $this->assertSame('Dockerfile', DockerfileFinder::find($this->dir, ['dockerfile' => true]));
    }

    public function testAHostUidBuildArgIsStillRefused(): void
    {
        file_put_contents($this->dir . '/Dockerfile', "FROM ubuntu\nARG WWWGROUP\nRUN groupadd --force -g \$WWWGROUP sail\n");

        $this->assertNull(DockerfileFinder::find($this->dir, ['dockerfile' => true]));
    }

    public function testAMissingSourceAfterAHeredocIsStillFound(): void
    {
        $this->assertSame('dist/app', DockerfileFinder::missingContextSource(
            "FROM alpine\nCOPY <<-EOT /etc/motd\n\thello\n\tEOT\nCOPY dist/app /usr/bin/app\n",
            $this->dir
        ));
    }

    public function testAPresentPlatformDirectoryIsAccepted(): void
    {
        foreach (['amd64', 'arm64'] as $arch) {
            mkdir("{$this->dir}/{$arch}");
            touch("{$this->dir}/{$arch}/app");
        }

        $this->assertNull(DockerfileFinder::missingContextSource(
            "FROM alpine\nARG TARGETARCH\nCOPY \$TARGETARCH/app /usr/bin/app\n",
            $this->dir
        ));
    }

    #[DataProvider('buildableDockerfiles')]
    public function testABuildableDockerfileIsNotRejected(string $contents): void
    {
        touch($this->dir . '/go.mod');
        touch($this->dir . '/go.sum');
        mkdir($this->dir . '/cmd');

        $this->assertNull(DockerfileFinder::missingContextSource($contents, $this->dir));
    }

    /** @return array<string, list<string>> */
    public static function buildableDockerfiles(): array
    {
        return [
            'whole context' => ["FROM golang\nCOPY . .\n"],
            'files that exist' => ["FROM golang\nCOPY go.mod go.sum ./\n"],
            'directory that exists' => ["FROM golang\nCOPY cmd ./cmd\n"],
            // The source is another stage's filesystem, not the context.
            'from another stage' => ["FROM golang AS build\nFROM alpine\nCOPY --from=build /app/x /usr/bin/x\n"],
            'from a named image' => ["FROM alpine\nCOPY --from=composer:2 /usr/bin/composer /usr/bin/composer\n"],
            // A URL is fetched, not read out of the context.
            'remote ADD' => ["FROM alpine\nADD https://example.com/x.tar.gz /tmp/\n"],
            // A glob says nothing certain about any one path.
            'glob' => ["FROM node\nCOPY package*.json ./\n"],
            'variable with no literal prefix' => ["FROM alpine\nCOPY \${SRC} /app\n"],
            'json form' => ["FROM golang\nCOPY [\"go.mod\", \"./\"]\n"],
            'continuation' => ["FROM golang\nCOPY \\\n  go.mod \\\n  go.sum ./\n"],
            // One argument names a destination and no source.
            'no source' => ["FROM alpine\nCOPY .\n"],
            'lowercase instruction' => ["FROM golang\ncopy go.mod ./\n"],
        ];
    }

    public function testTheJsonFormIsCheckedToo(): void
    {
        $this->assertSame('missing.txt', DockerfileFinder::missingContextSource(
            "FROM alpine\nCOPY [\"missing.txt\", \"/dest\"]\n",
            $this->dir
        ));
    }

    public function testAContinuedInstructionIsCheckedToo(): void
    {
        touch($this->dir . '/go.mod');

        $this->assertSame('go.sum', DockerfileFinder::missingContextSource(
            "FROM golang\nCOPY \\\n  go.mod \\\n  go.sum ./\n",
            $this->dir
        ));
    }

    /** A rejected root Dockerfile must not stop the search. */
    public function testAnUnbuildableRootDockerfileFallsThroughToTheNextCandidate(): void
    {
        file_put_contents($this->dir . '/Dockerfile', "FROM alpine\nCOPY dist/app /usr/bin/app\n");
        file_put_contents($this->dir . '/Dockerfile.alpine', "FROM alpine\nCOPY . .\n");

        $this->assertSame(
            'Dockerfile.alpine',
            DockerfileFinder::find($this->dir, ['dockerfile' => true, 'dockerfile.alpine' => true])
        );
    }

    /** With every candidate unbuildable, the rung declines and detection moves on. */
    public function testEveryUnbuildableCandidateLeavesNoDockerfile(): void
    {
        file_put_contents($this->dir . '/Dockerfile', "FROM alpine\nCOPY dist/app /usr/bin/app\n");
        file_put_contents($this->dir . '/Dockerfile.alpine', "FROM alpine\nCOPY dist/app /usr/bin/app\n");

        $this->assertNull(
            DockerfileFinder::find($this->dir, ['dockerfile' => true, 'dockerfile.alpine' => true])
        );
    }

    /** Xandikos ships a complete root Containerfile and nothing named Dockerfile. */
    public function testAContainerfileIsACandidate(): void
    {
        file_put_contents($this->dir . '/Containerfile', "FROM debian:sid-slim\nCOPY . /code\n");

        $this->assertSame('Containerfile', DockerfileFinder::find($this->dir, ['containerfile' => true]));
    }

    public function testADockerfileStillWinsOverAContainerfile(): void
    {
        file_put_contents($this->dir . '/Dockerfile', "FROM alpine\nCOPY . .\n");
        file_put_contents($this->dir . '/Containerfile', "FROM alpine\nCOPY . .\n");

        $this->assertSame(
            'Dockerfile',
            DockerfileFinder::find($this->dir, ['dockerfile' => true, 'containerfile' => true])
        );
    }

    /** The same usability check applies whatever the file is called. */
    public function testAnUnbuildableContainerfileIsNotACandidate(): void
    {
        file_put_contents($this->dir . '/Containerfile', "FROM alpine\nCOPY dist/app /usr/bin/app\n");

        $this->assertNull(DockerfileFinder::find($this->dir, ['containerfile' => true]));
    }

    public function testAContainerfileExposesItsPort(): void
    {
        file_put_contents($this->dir . '/Containerfile', "FROM debian:sid-slim\nEXPOSE 8000 8001\n");

        $this->assertSame(8000, DockerfileFinder::exposedPort($this->dir . '/Containerfile'));
    }

    // ---- a dotfile is a context file like any other ----------------------

    /**
     * github.com/mirotalk/c2c: the repo's own Dockerfile is fine, and it was
     * rejected for a file that was right there.
     *
     * `COPY .env.template ./.env` names a dotfile at the context root. The
     * prefix trim used a character class (`ltrim($source, './')`), which eats
     * the leading `.` as well as the `./`, so the source was read as
     * `env.template` -- not present -- and the Dockerfile was thrown out as
     * unbuildable. Detection then fell through to `express`, which does not
     * build this project at all.
     */
    public function testMirotalkCopiesADotfileFromTheContextRoot(): void
    {
        file_put_contents($this->dir . '/.env.template', "PORT=3010\n");
        mkdir($this->dir . '/app');

        $this->assertNull(DockerfileFinder::missingContextSource(
            "FROM node:18\nCOPY .env.template ./.env\nCOPY app /app\n",
            $this->dir
        ));
    }

    /** The dotfile really does have to be there. */
    public function testAMissingDotfileIsStillReported(): void
    {
        $this->assertSame(
            '.env.template',
            DockerfileFinder::missingContextSource(
                "FROM node:18\nCOPY .env.template ./.env\n",
                $this->dir
            )
        );
    }

    /**
     * And the fix is what lets the repo's own Dockerfile win.
     *
     * The same repository, before and after: with the dotfile present the root
     * Dockerfile is a candidate; without this fix it was rejected and the
     * caller got null.
     */
    public function testADockerfileCopyingADotfileIsStillACandidate(): void
    {
        file_put_contents($this->dir . '/.env.template', "PORT=3010\n");
        file_put_contents(
            $this->dir . '/Dockerfile',
            "FROM node:18\nCOPY .env.template ./.env\n"
        );

        $this->assertSame(
            'Dockerfile',
            DockerfileFinder::find($this->dir, ['dockerfile' => true])
        );
    }

    /**
     * `..` is refused as a path *segment*, not as a substring.
     *
     * `a..b/c` is a legitimate directory name, so it is verifiable -- and when
     * it is absent that now gets reported. The substring test this replaced
     * (`str_contains($source, '..')`) refused it outright, which made a
     * Dockerfile unbuildable for a directory that merely had dots in its name.
     */
    public function testADirectoryWhoseNameMerelyContainsDotDotIsReportedWhenMissing(): void
    {
        $this->assertSame(
            'a..b/c',
            DockerfileFinder::missingContextSource("FROM alpine\nCOPY a..b/c /opt/c\n", $this->dir)
        );
    }

    /** And it is accepted once the file is really there. */
    public function testADirectoryWhoseNameMerelyContainsDotDotIsAcceptedWhenPresent(): void
    {
        mkdir($this->dir . '/a..b');
        file_put_contents($this->dir . '/a..b/c', 'x');

        $this->assertNull(DockerfileFinder::missingContextSource(
            "FROM alpine\nCOPY a..b/c /opt/c\n",
            $this->dir
        ));
    }

    /**
     * A path that climbs out of the context is not checked at all.
     *
     * It cannot be verified -- the context is the whole build tree and the
     * parent is not in it -- so it is left alone rather than guessed at.
     * Refusing it would reject Dockerfiles that are in fact buildable.
     */
    public function testAPathClimbingOutOfTheContextIsLeftUnchecked(): void
    {
        $this->assertNull(DockerfileFinder::missingContextSource(
            "FROM alpine\nCOPY ../outside/app /opt/app\n",
            $this->dir
        ));

        // `sub/../sub` stays inside the context, so it is the same story.
        mkdir($this->dir . '/sub');
        $this->assertNull(DockerfileFinder::missingContextSource(
            "FROM alpine\nCOPY sub/../sub /opt/sub\n",
            $this->dir
        ));
    }

    /** An absolute source names the context root, as `.` and `./` do. */
    public function testAnAbsoluteSourceResolvesInsideTheContext(): void
    {
        file_put_contents($this->dir . '/app.js', 'x');

        $this->assertNull(DockerfileFinder::missingContextSource(
            "FROM alpine\nCOPY /app.js /opt/app.js\n",
            $this->dir
        ));
    }

    // ---- Docker cleans the source path before it looks it up -------------

    /**
     * github.com/se1exin/cleanarr: `COPY ./backend/requirements.txt/ /app`
     * builds, but `file_exists()` on a file with a trailing slash is false.
     */
    public function testCleanarrCopiesAFileWithATrailingSlash(): void
    {
        mkdir($this->dir . '/backend');
        touch($this->dir . '/backend/requirements.txt');
        file_put_contents($this->dir . '/Dockerfile', "FROM tiangolo/uwsgi-nginx-flask:python3.10\n"
            . "COPY ./backend/requirements.txt/ /app\nCOPY ./backend /app\n");

        $this->assertSame('Dockerfile', DockerfileFinder::find($this->dir, ['dockerfile' => true]));
    }

    #[DataProvider('uncleanPaths')]
    public function testAnUncleanPathToAPresentFileIsAccepted(string $source): void
    {
        mkdir($this->dir . '/backend');
        touch($this->dir . '/backend/requirements.txt');

        $this->assertNull(DockerfileFinder::missingContextSource("FROM alpine\nCOPY {$source} /app\n", $this->dir));
    }

    #[DataProvider('uncleanPaths')]
    public function testAnUncleanPathToAMissingFileIsStillReported(string $source): void
    {
        mkdir($this->dir . '/backend');

        $this->assertSame(
            $source,
            DockerfileFinder::missingContextSource("FROM alpine\nCOPY {$source} /app\n", $this->dir)
        );
    }

    /** @return array<string, list<string>> */
    public static function uncleanPaths(): array
    {
        return [
            'trailing slash' => ['backend/requirements.txt/'],
            'dot prefix and trailing slash' => ['./backend/requirements.txt/'],
            'trailing dot segment' => ['backend/requirements.txt/.'],
            'repeated slashes' => ['.//backend//requirements.txt//'],
            'inner dot segment' => ['backend/./requirements.txt'],
        ];
    }

    /** The GoReleaser case survives the cleaning: a gitignored `dist/` is absent. */
    public function testAMissingDirectoryWithATrailingSlashIsStillReported(): void
    {
        $this->assertSame('./dist//app/', DockerfileFinder::missingContextSource(
            "FROM alpine\nCOPY ./dist//app/ /usr/bin/app\n",
            $this->dir
        ));
    }

    /** `.` and `./` are the whole context, which exists by definition. */
    public function testTheContextRootItselfIsAccepted(): void
    {
        $this->assertNull(DockerfileFinder::missingContextSource(
            "FROM alpine\nCOPY . /app\nCOPY ./ /app2\n",
            $this->dir
        ));
    }
}
