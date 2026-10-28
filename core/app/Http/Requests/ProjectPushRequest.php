<?php

namespace App\Http\Requests;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\System;
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
            'tables' => 'sometimes|array|max:500',
            'tables.*.database' => 'required|string',
            'tables.*.name' => ['required', 'string', 'regex:/^[A-Za-z0-9_$]+$/D'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $this->boolean('files', true) && ! $this->boolean('databases', true)) {
                throw ProblemException::one(
                    'files',
                    'nothing_to_push',
                    'Nothing to push: both files and databases are disabled.',
                );
            }

            $username = $this->route('username');
            $target = $this->input('target');
            if (! is_string($username) || $username === '' || ! is_string($target) || $target === '') {
                return;
            }

            $from = User::findByUsername($username);
            $to = User::findByUsername($target);
            if ($from === null || $to === null) {
                return;
            }

            $problems = [
                ...self::pushProblems($from, $to),
                ...Projects::emptyContainerPushProblems($from, $to, $this->boolean('files', true), $this->boolean('databases', true)),
            ];
            if ($problems !== []) {
                throw ProblemException::of($problems);
            }

            $this->assertTablesApplicable($from);
        });
    }

    private function assertTablesApplicable(User $from): void
    {
        $tables = $this->input('tables');
        if (! is_array($tables) || $tables === []) {
            return;
        }

        if (! $this->boolean('databases', true)) {
            throw ProblemException::one(
                'tables',
                'tables_not_applicable',
                'Selecting tables requires the databases flag.',
            );
        }

        if (Projects::isDind($from)) {
            throw ProblemException::one(
                'tables',
                'tables_not_applicable',
                'Selecting tables is only available for classic projects.',
            );
        }

        $problems = Projects::tableProblems($from, $tables, app(System::class)->mysql()->tables());
        if ($problems !== []) {
            throw ProblemException::of($problems);
        }
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
