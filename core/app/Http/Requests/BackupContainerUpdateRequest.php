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

            $driver = $this->input('driver');
            $location = $this->input('location');
            $resolvedDriver = is_string($driver) ? $driver : null;
            if ($resolvedDriver === null) {
                $container = BackupContainer::find((int) $this->route('id'));
                $resolvedDriver = $container?->driver;
            }

            if ($resolvedDriver === 'local' && is_string($location) && $location !== '') {
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

            if (! is_string($driver) || $driver === 'local' || $this->has('credentials')) {
                return;
            }

            $container = BackupContainer::find((int) $this->route('id'));
            if ($container !== null && $container->credentials === null) {
                $validator->errors()->add(
                    'credentials',
                    'Credentials are required for non-local backup storage.',
                );
            }
        });
    }
}
