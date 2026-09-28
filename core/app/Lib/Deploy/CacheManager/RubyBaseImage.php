<?php

namespace App\Lib\Deploy\CacheManager;

/**
 * A PanelAlpha-owned `ruby:*-slim-bookworm` derivative with the apt packages
 * native gems need already installed.
 *
 * Same trade as {@see PhpBaseImage}: `apt-get install build-essential
 * libpq-dev …` costs ~14s inside every account's build, produces a
 * byte-identical result every time, and cannot be shared through the registry
 * cache because a tenant build must never export layers. Building it once on
 * the host takes that off the deploy path for every account that follows;
 * {@see \App\Lib\Deploy\Engine\ImageStore::hostBuildCommand()} builds it.
 *
 * The package set varies by which database gem the Gemfile asks for, so the
 * tag carries a fingerprint of it — a Postgres app and a MySQL app get
 * different bases and neither serves the other a wrong one.
 */
final class RubyBaseImage extends RuntimeBaseImage
{
    protected const RUNTIME = 'ruby';

    public const MAX_PACKAGES = 8;
}
