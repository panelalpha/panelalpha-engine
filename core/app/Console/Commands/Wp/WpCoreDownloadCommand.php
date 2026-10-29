<?php

namespace App\Console\Commands\Wp;

use App\Http\Requests\Wp\WpCoreDownloadRequest;
use App\System\Project\PhpHosting\WordPress\WordPress;
use InvalidArgumentException;

class WpCoreDownloadCommand extends WordPressCommand
{
    protected $signature = 'wordpress:core:download
                            {--locale= : WordPress language, such as pl_PL; omitted, en_US}
                            {--wp-version= : WordPress version, or latest; omitted, the latest release}';

    protected $description = 'Download WordPress core into the project document root (PHP hosting only, never over an existing core)';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->core()->download(
            $this->matchingOption('locale', WpCoreDownloadRequest::LOCALE_PATTERN),
            $this->matchingOption('wp-version', WpCoreDownloadRequest::VERSION_PATTERN),
        );
    }

    private function matchingOption(string $name, string $pattern): ?string
    {
        $value = $this->optionalOption($name);
        if ($value !== null && preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException("Invalid --{$name}: {$value}");
        }

        return $value;
    }
}
