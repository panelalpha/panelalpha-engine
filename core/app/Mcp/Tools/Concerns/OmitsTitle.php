<?php

namespace App\Mcp\Tools\Concerns;

/**
 * Leaves `title` out of the tool definition. laravel/mcp fills it from the
 * class name ("Project Delete Tool"), which says nothing `name` does not and
 * costs every client a few tokens per tool on every connect. It is optional
 * in MCP.
 */
trait OmitsTitle
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $definition = parent::toArray();
        unset($definition['title']);

        return $definition;
    }
}
