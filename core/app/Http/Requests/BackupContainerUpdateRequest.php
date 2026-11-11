<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesBackupContainerCredentials;
use App\Models\BackupContainer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class BackupContainerUpdateRequest extends FormRequest
{
    use NormalizesBackupContainerCredentials;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        BackupContainer::findOrFail((int) $this->route('id'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', Rule::unique('backup_containers', 'name')->ignore($this->route('id'))],
            'driver' => ['sometimes', Rule::in(['local', 's3', 'ftp', 'ftps', 'sftp'])],
            'location' => ['sometimes', 'string', 'max:1024'],
            'credentials' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $container = BackupContainer::find((int) $this->route('id'));
            $driver = $this->input('driver');
            $resolvedDriver = is_string($driver) ? $driver : $container?->driver;

            if ($resolvedDriver === 'local') {
                $this->checkEffectiveLocalLocation($validator, $container);
            }

            if (! is_string($driver) || $driver === 'local' || $this->has('credentials')) {
                return;
            }

            if ($container !== null && $container->credentials === null) {
                $validator->errors()->add(
                    'credentials',
                    'Credentials are required for non-local backup storage.',
                );
            }
        });
    }

    /** The location a local store ends up with, the one sent or the one it keeps, must pass the local rules. */
    private function checkEffectiveLocalLocation(Validator $validator, ?BackupContainer $container): void
    {
        $sent = $this->input('location');
        $location = is_string($sent) && $sent !== ''
            ? $sent
            : (is_string($container?->location) ? $container->location : '');

        try {
            $this->assertLocalLocationIsSafe($location);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }
    }
}
