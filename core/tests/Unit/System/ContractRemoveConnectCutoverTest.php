<?php

namespace Tests\Unit\System;

use App\Models\Domain;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Production cutover issue 11: connect() and Lib\Apis\System are gone outside the unused Lib tree.
 */
final class ContractRemoveConnectCutoverTest extends TestCase
{
    public function test_models_user_and_domain_do_not_expose_connect(): void
    {
        $this->assertFalse(method_exists(User::class, 'connect'));
        $this->assertFalse(method_exists(Domain::class, 'connect'));
    }

    public function test_app_tree_outside_lib_apis_system_has_no_lib_system_imports(): void
    {
        $hits = [];
        $appRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app';
        $libSystemPrefix = $appRoot . DIRECTORY_SEPARATOR . 'Lib' . DIRECTORY_SEPARATOR . 'Apis' . DIRECTORY_SEPARATOR . 'System';

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_starts_with($path, $libSystemPrefix)) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                $hits[] = $path . ' (unreadable)';
                continue;
            }
            if (preg_match('/App\\\\Lib\\\\Apis\\\\System/', $contents) === 1) {
                $hits[] = str_replace($appRoot . DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame([], $hits, implode("\n", $hits));
    }

    public function test_app_tree_outside_lib_apis_system_has_no_connect_calls(): void
    {
        $hits = [];
        $appRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app';
        $libSystemPrefix = $appRoot . DIRECTORY_SEPARATOR . 'Lib' . DIRECTORY_SEPARATOR . 'Apis' . DIRECTORY_SEPARATOR . 'System';

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_starts_with($path, $libSystemPrefix)) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                $hits[] = $path . ' (unreadable)';
                continue;
            }
            if (preg_match('/->connect\\(/', $contents) === 1) {
                $hits[] = str_replace($appRoot . DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame([], $hits, implode("\n", $hits));
    }
}
