<?php

namespace App\Rules;

use App\Lib\Upload\Uploads;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * An uploaded file named as an archive the deploy imports: the name decides
 * the format, since the upload is not unpacked until a deploy takes it.
 */
final class ArchiveFileName implements ProblemRule
{
    use RaisesProblem;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof UploadedFile && Uploads::isArchiveName($value->getClientOriginalName())) {
            return;
        }

        $this->reject([
            'field' => $attribute,
            'code' => 'file_not_an_archive',
            'message' => 'Upload a .zip, .tar.gz or .tgz archive; the file name says which format it is.',
            'expected' => 'a file named *.zip, *.tar.gz or *.tgz',
        ], $fail);
    }
}
