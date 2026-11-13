<?php

namespace Tests\Unit\Rules;

use App\Rules\ArchiveFileName;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ArchiveFileNameTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function archiveNames(): array
    {
        return [
            'zip' => ['site.zip'],
            'tar.gz' => ['site.tar.gz'],
            'tgz' => ['site.tgz'],
            'upper case suffix' => ['SITE.ZIP'],
            'a Windows path keeps only the name' => ['C:\\Users\\me\\site.zip'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function otherNames(): array
    {
        return [
            'no suffix' => ['site'],
            'a plain tar' => ['site.tar'],
            'a gzip that is not a tarball' => ['site.gz'],
            'rar' => ['site.rar'],
            'suffix only in the middle' => ['site.zip.txt'],
            'a name that is only a path' => ['uploads/'],
            'a name over 200 characters' => [str_repeat('a', 200).'.zip'],
        ];
    }

    #[DataProvider('archiveNames')]
    public function test_user_uploading_a_named_archive_is_accepted(string $name): void
    {
        $rule = new ArchiveFileName;

        $this->assertFalse($this->rejects($rule, $name));
        $this->assertNull($rule->problem());
    }

    #[DataProvider('otherNames')]
    public function test_user_uploading_a_file_not_named_as_an_archive_gets_a_coded_problem(string $name): void
    {
        $rule = new ArchiveFileName;

        $this->assertTrue($this->rejects($rule, $name));
        $this->assertSame('file_not_an_archive', $rule->problem()['code'] ?? null);
        $this->assertSame('file', $rule->problem()['field'] ?? null);
    }

    public function test_user_sending_a_text_field_instead_of_a_file_is_refused(): void
    {
        $rule = new ArchiveFileName;
        $failed = false;

        $rule->validate('file', 'site.zip', function () use (&$failed): void {
            $failed = true;
        });

        $this->assertTrue($failed);
        $this->assertSame('file_not_an_archive', $rule->problem()['code'] ?? null);
    }

    private function rejects(ArchiveFileName $rule, string $clientName): bool
    {
        $failed = false;
        $file = new UploadedFile(__FILE__, $clientName, null, null, true);

        $rule->validate('file', $file, function () use (&$failed): void {
            $failed = true;
        });

        return $failed;
    }
}
