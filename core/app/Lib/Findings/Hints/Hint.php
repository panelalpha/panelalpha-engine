<?php

namespace App\Lib\Findings\Hints;

use Illuminate\Support\Str;

/**
 * One thing that resolves a finding, written once for every surface: {@see HintBuilder} turns it into its REST, MCP
 * and CLI forms. The class name is the action a client sees, `RequestCertificate` is `request_certificate`.
 */
abstract class Hint
{
    /**
     * @return list<string> the finding codes this acts on
     */
    abstract public function resolves(): array;

    public function action(): string
    {
        return Str::snake(class_basename(static::class));
    }

    /** The operation as `app/Mcp/tool-names.php` keys it, "VERB /path"; null for what only an operator on the host can do. */
    public function rest(): ?string
    {
        return null;
    }

    /**
     * @return array<string, scalar|null> API parameter => literal, `{fact}`, or null for the caller to supply
     */
    public function params(): array
    {
        return [];
    }

    /**
     * @return list<string> API parameters left out when nothing known fills them, rather than left for the caller
     */
    public function optional(): array
    {
        return [];
    }

    /** The artisan command; `{param}` is filled from the same values as the REST call. */
    public function cli(): ?string
    {
        return null;
    }

    /**
     * @return list<string> codes whose presence in the same answer makes this action fail
     */
    public function unless(): array
    {
        return [];
    }
}
