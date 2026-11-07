<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * Java identity, from pom.xml (a Gradle build is a program and is not read). Parsed with network and entity
 * loading off: the file comes from a tenant's repository.
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
            return $this->unreadable($context);
        }

        return $this->package($context, $pom);
    }

    /** The file is there and unreadable: naming the ecosystem without claiming anything else is the honest report. */
    private function unreadable(ProjectContext $context): AppPackage
    {
        return new AppPackage(
            ecosystem: $this->id(),
            file: self::FILE,
            name: self::directoryName($context),
            nameSource: AppPackage::NAME_FROM_DIRECTORY
        );
    }

    /** `<artifactId>` is the name; the `<name>` title is better shown than depended on, so it is the fallback description. */
    private function package(ProjectContext $context, \SimpleXMLElement $pom): AppPackage
    {
        $artifactId = self::at($pom, 'artifactId');
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
            frameworks: self::matchFrameworks(self::FRAMEWORKS, $artifacts, $artifacts, self::FILE, self::FILE),
            dependencyCounts: ['dependencies' => count(self::each($pom, 'dependencies', 'dependency'))]
        );
    }

    /**
     * Every artifactId the POM names with its declared version, the parent included (Spring Boot is a parent);
     * a managed dependency declares none, which is `*`, not a missing value.
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
            $artifacts[$id] = self::at($dependency, 'version') ?? '*';
        }

        return $artifacts;
    }

    /**
     * One element's text, reached without walking through a missing parent: `$pom->scm->url` on a POM without
     * `<scm>` is a PHP warning, and a POM missing its optional blocks is the ordinary case.
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
     * The repeated children of a wrapper element, such as `<dependencies>`, and an empty list when either is absent.
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
