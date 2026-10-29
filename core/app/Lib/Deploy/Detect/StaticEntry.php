<?php

namespace App\Lib\Deploy\Detect;

/**
 * The HTML file a static site is served from: the project's own index first,
 * then a single stray .html file, then the engine's placeholder.
 */
final class StaticEntry
{
    /** @var list<string> */
    private const INDEX_NAMES = ['index.html', 'index.htm'];

    private const HTML_PATTERN = '/\.html?$/i';

    /**
     * @param  array<string, true>  $files  lowercase basename => true
     */
    public function __construct(private readonly string $projectDir, private readonly array $files) {}

    /**
     * @param  array<string, true>  $files  lowercase basename => true
     */
    public static function find(string $projectDir, array $files): ?string
    {
        return (new self(rtrim($projectDir, '/'), $files))->locate();
    }

    public function locate(): ?string
    {
        return $this->projectIndex() ?? $this->soleHtmlFile() ?? $this->anyIndex();
    }

    /** An index the project wrote, as opposed to the engine's placeholder. */
    private function projectIndex(): ?string
    {
        foreach ($this->presentIndexes() as $name) {
            if (! PlaceholderPage::isOneOf($this->path($name))) {
                return $name;
            }
        }

        return null;
    }

    /** The only HTML file in the root, when there is exactly one. */
    private function soleHtmlFile(): ?string
    {
        $found = [];
        foreach (scandir($this->projectDir) ?: [] as $entry) {
            if ($this->isProjectHtml($entry)) {
                $found[] = $entry;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /** Last resort: an index even if it is the engine's own placeholder. */
    private function anyIndex(): ?string
    {
        return $this->presentIndexes()[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function presentIndexes(): array
    {
        return array_values(array_filter(
            self::INDEX_NAMES,
            fn (string $name): bool => isset($this->files[$name]) && is_file($this->path($name))
        ));
    }

    private function isProjectHtml(string $entry): bool
    {
        return preg_match(self::HTML_PATTERN, $entry) === 1
            && is_file($this->path($entry))
            && ! PlaceholderPage::isOneOf($this->path($entry));
    }

    private function path(string $name): string
    {
        return $this->projectDir.'/'.$name;
    }
}
