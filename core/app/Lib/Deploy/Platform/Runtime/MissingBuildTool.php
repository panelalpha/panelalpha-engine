<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * A host Node compile that died because the slim image lacks a program the full `node:<major>-bookworm` image
 * carries: a script or a dependency's postinstall ran git, curl, python3 or make, which no file names in advance.
 */
final class MissingBuildTool
{
    /** The programs the full image adds to the slim one that builds reach for. */
    private const TOOLS = 'git|curl|wget|python3|make|gcc|g\+\+|unzip|xz|bzip2|patch';

    /** The tool $failure says was not found, or null when it names none the full image carries. */
    public static function in(string $failure): ?string
    {
        $tools = self::TOOLS;
        $patterns = [
            "~(?:^|[\\s/])(?:[\\w.-]*\\.sh|sh|bash|dash)(?::\\s*(?:\\d+:|line \\d+:))?\\s*({$tools}):\\s*(?:command\\s+)?not found~m",
            "~\\bspawn(?:Sync)? ({$tools}) ENOENT\\b~",
            "~Couldn't find the binary ({$tools})\\b~",
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $failure, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }
}
