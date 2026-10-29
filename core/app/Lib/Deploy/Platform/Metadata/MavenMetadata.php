<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Java identity, from pom.xml.
 *
 * Maven is the half of the Java world that keeps its metadata in a document.
 * The other half keeps it in `build.gradle`, which is a Groovy or Kotlin
 * program, and is not read here — a Gradle project reports no Java package
 * rather than whatever a regex could scrape out of executable code.
 *
 * The Spring Boot version is worth the special case: it is declared as the
 * parent POM's version rather than as a dependency, so a project can be built
 * entirely on Spring Boot 3.2 without `spring-boot` appearing in a single
 * `<dependency>` block.
 *
 * The XML is parsed with network and entity loading off. A pom.xml comes out
 * of a tenant's repository, and an inspection endpoint that resolved external
 * entities on it would be reading files off this server on request.
 *
 * No Laravel dependencies — unit-testable with a temp directory.
 */
final class MavenMetadata implements PackageMetadata
{
    use ReadsPackageFiles;

    public const FILE = 'pom.xml';

    /**
     * Java frameworks worth naming, by the artifact that gives them away.
     *
     * @var array<string, string>
     */
    private const FRAMEWORKS = [
        'spring-boot-starter-parent' => 'Spring Boot',
        'spring-boot-starter-web' => 'Spring Boot',
        'quarkus-universe-bom' => 'Quarkus',
        'quarkus-resteasy' => 'Quarkus',
        'micronaut-http-server-netty' => 'Micronaut',
        'helidon-microprofile' => 'Helidon',
        'dropwizard-core' => 'Dropwizard',
        'javalin' => 'Javalin',
    ];

    public function id(): string
    {
        return 'java';
    }

    public function read(ProjectContext $context): ?AppPackage
    {
        $raw = $context->contents(self::FILE);
        if ($raw === null) {
            return null;
        }

        $pom = @simplexml_load_string($raw, null, LIBXML_NONET | LIBXML_NOCDATA);
        if ($pom === false) {
            // The file is there and unreadable. Naming the ecosystem without
            // pretending to know anything else about it is the honest report.
            return new AppPackage(
                ecosystem: $this->id(),
                file: self::FILE,
                name: self::directoryName($context),
                nameSource: AppPackage::NAME_FROM_DIRECTORY
            );
        }

        $artifactId = self::at($pom, 'artifactId');
        // `<name>` is a human title and `<artifactId>` is the identifier. The
        // title is better to show and worse to depend on, so the identifier
        // wins and the title becomes the description when there is none.
        $title = self::at($pom, 'name');
        $artifacts = self::artifacts($pom);

        return new AppPackage(
            ecosystem: $this->id(),
            file: self::FILE,
            name: $artifactId ?? self::directoryName($context),
            nameSource: $artifactId !== null ? self::FILE.' artifactId' : AppPackage::NAME_FROM_DIRECTORY,
            description: self::at($pom, 'description') ?? $title,
            version: self::at($pom, 'version') ?? self::at($pom, 'parent', 'version'),
            license: self::at($pom, 'licenses', 'license', 'name'),
            homepage: self::at($pom, 'url'),
            repository: self::at($pom, 'scm', 'url'),
            authors: self::developers($pom),
            frameworks: self::matchFrameworks(
                self::FRAMEWORKS,
                $artifacts,
                $artifacts,
                self::FILE,
                self::FILE
            ),
            dependencyCounts: ['dependencies' => count(self::each($pom, 'dependencies', 'dependency'))]
        );
    }

    /**
     * Every artifactId the POM names, with the version declared beside it.
     *
     * The parent is included, which is the point: Spring Boot is a parent, not
     * a dependency.
     *
     * @return array<string, string>
     */
    private static function artifacts(\SimpleXMLElement $pom): array
    {
        $artifacts = [];

        $parentId = self::at($pom, 'parent', 'artifactId');
        if ($parentId !== null) {
            $artifacts[$parentId] = self::at($pom, 'parent', 'version') ?? '*';
        }

        foreach (self::each($pom, 'dependencies', 'dependency') as $dependency) {
            $id = self::at($dependency, 'artifactId');
            if ($id === null || isset($artifacts[$id])) {
                continue;
            }
            // A managed dependency inherits its version from the parent BOM
            // and declares none of its own, which is not a missing value.
            $artifacts[$id] = self::at($dependency, 'version') ?? '*';
        }

        return $artifacts;
    }

    /**
     * One element's text, reached without walking through a missing parent.
     *
     * SimpleXML answers null for the child of an element that has none, so
     * `$pom->scm->url` on a POM without an `<scm>` block is a PHP warning
     * rather than an empty string — and a POM missing most of its optional
     * blocks is the ordinary case, not the broken one.
     */
    private static function at(?\SimpleXMLElement $node, string ...$names): ?string
    {
        foreach ($names as $name) {
            if (! $node instanceof \SimpleXMLElement) {
                return null;
            }
            $node = $node->{$name};
        }

        return $node instanceof \SimpleXMLElement ? self::text((string) $node) : null;
    }

    /**
     * The repeated children of a wrapper element — `<dependencies>` holding
     * any number of `<dependency>` — and an empty list when either is absent.
     *
     * @return list<\SimpleXMLElement>
     */
    private static function each(\SimpleXMLElement $node, string $wrapper, string $child): array
    {
        $parent = $node->{$wrapper};
        if (! $parent instanceof \SimpleXMLElement) {
            return [];
        }
        $children = $parent->{$child};
        if (! $children instanceof \SimpleXMLElement) {
            return [];
        }

        return iterator_to_array($children, false);
    }

    /**
     * @return list<string>
     */
    private static function developers(\SimpleXMLElement $pom): array
    {
        $developers = [];
        foreach (self::each($pom, 'developers', 'developer') as $developer) {
            $name = self::at($developer, 'name') ?? self::at($developer, 'id');
            if ($name !== null) {
                $developers[$name] = true;
            }
        }

        return array_keys($developers);
    }
}
