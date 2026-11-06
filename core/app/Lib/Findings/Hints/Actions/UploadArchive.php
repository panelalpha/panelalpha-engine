<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;
use App\Lib\Upload\ArchiveSources;
use App\Lib\Upload\Uploads;

/** The archive uploaded, for the id a deploy takes. */
final class UploadArchive extends Hint
{
    public function resolves(): array
    {
        return [Uploads::NOT_FOUND, Uploads::EXPIRED, ArchiveSources::UPLOAD_GONE];
    }

    public function rest(): string
    {
        return 'POST /uploads';
    }

    public function params(): array
    {
        return ['file' => null];
    }
}
