<?php

namespace App\Http\Requests;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\System\Projects;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProjectPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target' => 'required|string',
            'files' => 'sometimes|boolean',
            'databases' => 'sometimes|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (!$this->boolean('files', true) && !$this->boolean('databases', true)) {
                throw ProblemException::one(
                    'files',
                    'nothing_to_push',
                    'Nothing to push: both files and databases are disabled.',
                );
            }

            $username = $this->route('username');
            $target = $this->input('target');
            if (!is_string($username) || $username === '' || !is_string($target) || $target === '') {
                return;
            }

            $from = User::findByUsername($username);
            $to = User::findByUsername($target);
            if ($from === null || $to === null) {
                return;
            }

            $problems = self::pushProblems($from, $to);
            if ($problems !== []) {
                throw ProblemException::of($problems);
            }
        });
    }

    public static function assertCanPush(User $from, User $to): void
    {
        Projects::assertCanPush($from, $to);
    }

    /**
     * @return list<array{field: string, code: string, message: string}>
     */
    public static function pushProblems(User $from, User $to): array
    {
        return Projects::pushProblems($from, $to);
    }
}
