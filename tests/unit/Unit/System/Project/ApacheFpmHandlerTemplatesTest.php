<?php

namespace Tests\Unit\System\Project;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * mod_proxy_fcgi drops the Authorization header unless the handler block allows it, which
 * leaves WordPress application passwords and every PHP app's own HTTP auth without credentials.
 */
class ApacheFpmHandlerTemplatesTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function templates(): array
    {
        return [
            'project vhost' => ['virtualHost-apache-user.blade.php'],
            'host apache vhost' => ['virtualHost-apache.blade.php'],
            'domain template' => ['domain-apache.conf'],
        ];
    }

    #[DataProvider('templates')]
    public function test_every_php_handler_passes_the_authorization_header(string $template): void
    {
        $blocks = $this->phpHandlerBlocks($template);

        $this->assertNotEmpty($blocks, "{$template} has no PHP handler block");
        foreach ($blocks as $block) {
            $this->assertMatchesRegularExpression('/^\s*CGIPassAuth On$/m', $block);
        }
    }

    /** @return list<string> */
    private function phpHandlerBlocks(string $template): array
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 5).'/templates/'.$template);
        preg_match_all('#<FilesMatch "\\\\\.php\$">(.*?)</FilesMatch>#s', $contents, $matches);

        return $matches[1];
    }
}
