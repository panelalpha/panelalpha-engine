<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReportsProblems;
use App\Rules\ArchiveFileName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/** POST /uploads: one archive, named as one. */
class UploadStoreRequest extends FormRequest
{
    use ReportsProblems;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', new ArchiveFileName]];
    }

    public function archive(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->file('file');
    }
}
