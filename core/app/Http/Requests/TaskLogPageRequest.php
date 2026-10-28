<?php

namespace App\Http\Requests;

use App\Lib\Task\TaskLogPage;
use Illuminate\Foundation\Http\FormRequest;

class TaskLogPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'after_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.TaskLogPage::MAX_LIMIT],
        ];
    }

    public function afterId(): int
    {
        return (int) $this->query('after_id', 0);
    }

    public function limit(): int
    {
        $limit = $this->query('limit');

        return is_numeric($limit) ? (int) $limit : TaskLogPage::DEFAULT_LIMIT;
    }
}
