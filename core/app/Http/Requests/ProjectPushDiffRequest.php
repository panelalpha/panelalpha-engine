<?php

namespace App\Http\Requests;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\System\Projects;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProjectPushDiffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target' => 'required|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
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

            $problems = ProjectPushRequest::pushProblems($from, $to);
            if ($problems !== []) {
                throw ProblemException::of($problems);
            }

            if (Projects::isDind($from)) {
                throw ProblemException::one(
                    'target',
                    'tables_not_applicable',
                    'Comparing database tables is only available for classic projects.',
                );
            }
        });
    }
}
