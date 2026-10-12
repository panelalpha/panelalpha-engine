<?php

namespace App\Rules;

/** The hostname grammar every domain field is validated against, for a `regex:` rule. */
final class DomainName
{
    /** The D modifier: `$` must not match before a trailing newline. */
    public const REGEX = '/^(?!:\/\/)(?=.{1,255}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/iD';
}
