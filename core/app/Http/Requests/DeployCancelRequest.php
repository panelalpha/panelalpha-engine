<?php

namespace App\Http\Requests;

use App\Exceptions\ProblemException;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DeployCancelRequest extends FormRequest
{
    public const CONTAINER_ONLY = 'deploy_cancel_container_only';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    /** Only a container project runs a deploy to cancel; a project that does not exist is the controller's 404. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (): void {
            $user = User::findByUsername((string) $this->route('username'));
            if ($user === null || $user->getTemplate() === 'dind') {
                return;
            }

            throw ProblemException::one(
                'project',
                self::CONTAINER_ONLY,
                'Deploy cancel works only on container projects (template dind).',
            );
        });
    }
}
